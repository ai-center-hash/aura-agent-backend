<?php

namespace App\Services\Chat;

use App\Enums\Agent;
use App\Enums\MessageRole;
use App\Exceptions\UpstreamServiceException;
use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\Agent\ActAs;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentRegistry;
use App\Services\YruPassport\YruPassportService;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Chat pipeline: every answer comes from an agent (App\Enums\Agent):
 * AURA-AGENT (activities, Activity API) or FREYA-AGENT (registrar). The
 * agent reads the user's real data and keeps its own memory of each
 * conversation (thread_id), so a session stays with one agent. Laravel
 * authenticates the user, passes on their YRU Passport token, streams the
 * answer through and keeps a copy of the conversation for the history screens.
 *
 * Messages are only written after an answer is complete, and every question
 * leaves one ai_requests row (AiTrace).
 */
class ChatService
{
    public const PASSPORT_REQUIRED = 'กรุณาเข้าสู่ระบบด้วย YRU Passport ก่อนใช้งาน AURA';

    public const SUPPORT_UNAVAILABLE = 'ยังถามแทนนักศึกษาไม่ได้ ต้องรอให้ AURA-AGENT เปิดโหมดถามแทน (AURA_AGENT_SUPPORT_KEY)';

    public function __construct(
        private readonly AgentRegistry $agents,
        private readonly YruPassportService $passport,
    ) {}

    /**
     * Wait for the whole answer. With $actAs (already authorised by the
     * "view-student" gate) an admin or instructor asks about that student.
     * $agent picks the agent of a new session (default AURA); for an existing
     * session it must match, or be null.
     *
     * @return array{session: ChatSession, question: ChatMessage, message: ChatMessage}
     */
    public function reply(User $user, string $question, ?int $sessionId = null, string $channel = 'api', ?ActAs $actAs = null, ?Agent $agent = null): array
    {
        $session = $this->resolveSession($user, $sessionId, $question, $actAs, $agent);

        return $this->drain($this->run($session, $question, $channel, $actAs));
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(User $user, string $question, ?int $sessionId = null, string $channel = 'api_stream', ?ActAs $actAs = null, ?Agent $agent = null): Generator
    {
        $session = $this->resolveSession($user, $sessionId, $question, $actAs, $agent);

        yield from $this->run($session, $question, $channel, $actAs);
    }

    /**
     * @return array{session: ChatSession, question: ChatMessage, message: ChatMessage}
     */
    public function replyInSession(ChatSession $session, string $question, string $channel = 'openwebui'): array
    {
        return $this->drain($this->run($session, $question, $channel));
    }

    /**
     * Wait for a run to finish; the events only matter when streaming.
     */
    private function drain(Generator $run): array
    {
        foreach ($run as $event) {
            // discard
        }

        return $run->getReturn();
    }

    /**
     * Stream the answer: "status" while the agent looks something up, "delta"
     * chunks of text, then "done" once saved. Problems are thrown as
     * UpstreamServiceException (errorCode: PASSPORT_REQUIRED, NOT_STUDENT,
     * TOKEN_EXPIRED, UPSTREAM_UNAVAILABLE, LLM_UNAVAILABLE, ...).
     * If the consumer stops early (client disconnected) the trace is saved as "aborted".
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function streamInSession(ChatSession $session, string $question, string $channel = 'openwebui'): Generator
    {
        yield from $this->run($session, $question, $channel);
    }

    /**
     * Find (or prepare, unsaved) the session mirroring a chat that lives in an
     * external client such as Open WebUI. A new session is bound to $agent;
     * an existing one bound to another agent is refused (AGENT_MISMATCH),
     * e.g. the user switched model in the middle of an Open WebUI chat.
     */
    public function sessionForExternalChat(User $user, string $externalId, string $firstQuestion, Agent $agent = Agent::Aura): ChatSession
    {
        $session = $user->chatSessions()->firstOrNew(
            ['external_id' => $externalId],
            ['title' => Str::limit(Str::squish($firstQuestion), 60), 'agent' => $agent],
        );

        if ($session->agent !== $agent) {
            throw new UpstreamServiceException($agent->engine(), self::agentMismatch($session->agent), 422, errorCode: 'AGENT_MISMATCH');
        }

        return $session;
    }

    public static function agentMismatch(Agent $sessionAgent): string
    {
        return "ห้องแชทนี้คุยกับ {$sessionAgent->label()} อยู่ กรุณาเริ่มแชทใหม่เพื่อเปลี่ยนผู้ช่วย";
    }

    /**
     * @return Generator<int, array<string, mixed>, mixed, array{session: ChatSession, question: ChatMessage, message: ChatMessage}>
     */
    private function run(ChatSession $session, string $question, string $channel, ?ActAs $actAs = null): Generator
    {
        $agent = $session->agent;
        $client = $this->agents->for($agent);
        $trace = AiTrace::start($channel, $session->user_id, $question, engine: $agent->engine());
        $trace->actingAs($actAs?->studentCode);
        $session->agent_thread_id ??= (string) Str::uuid();
        $status = 'aborted';
        $error = null;
        $result = null;

        try {
            $token = $actAs ? $this->assertSupportMode($client) : $this->passportToken($client, $session->user);

            $trace->llmStarted(str_replace('_', '-', $agent->engine()));
            $content = '';

            foreach ($client->chat($token, $session->agent_thread_id, $question, $actAs) as $event) {
                $data = $event['data'];

                switch ($event['event']) {
                    case 'status':
                        yield ['type' => 'status', 'text' => (string) ($data['text'] ?? '')];
                        break;

                    case 'token':
                        $trace->firstToken();
                        $content .= $text = (string) ($data['text'] ?? '');
                        yield ['type' => 'delta', 'content' => $text];
                        break;

                    case 'error':
                        $code = (string) ($data['code'] ?? 'INTERNAL');

                        if ($code === 'TOKEN_EXPIRED') {
                            $this->passport->forgetTokens($session->user);
                        }

                        throw new UpstreamServiceException(
                            $agent->engine(),
                            (string) ($data['text'] ?? AgentClient::UNAVAILABLE),
                            match ($code) {
                                'TOKEN_EXPIRED' => 401,
                                'NOT_STUDENT' => 403,
                                default => 503,
                            },
                            errorCode: $code,
                        );

                    case 'done':
                        // Token counts are null when the agent doesn't report them in its done event.
                        $stats = [
                            'tools_used' => $data['tools_used'] ?? [],
                            'latency_ms' => $data['latency_ms'] ?? null,
                            'prompt_tokens' => isset($data['prompt_tokens']) ? (int) $data['prompt_tokens'] : null,
                            'completion_tokens' => isset($data['completion_tokens']) ? (int) $data['completion_tokens'] : null,
                        ];
                        $trace->llmFinished($stats);

                        $result = $this->persist($session, $question, $content, ['engine' => $agent->engine(), ...$stats]);
                        $status = 'success';

                        yield [
                            'type' => 'done',
                            'session_id' => $result['session']->id,
                            'message_id' => $result['message']->id,
                            'agent' => $agent->value,
                            'acting_as' => $actAs?->studentCode,
                            'tools_used' => $stats['tools_used'],
                            'latency_ms' => $stats['latency_ms'],
                            'usage' => self::usage($stats),
                        ];

                        return $result;
                }
            }

            throw new UpstreamServiceException($agent->engine(), AgentClient::UNAVAILABLE, 503, errorCode: 'INCOMPLETE');
        } catch (Throwable $e) {
            $status = 'error';
            $error = $e instanceof UpstreamServiceException && $e->errorCode ? "{$e->errorCode}: {$e->getMessage()}" : $e->getMessage();

            throw $e;
        } finally {
            $trace->finish($status, $result['session'] ?? $session, $result['question'] ?? null, $result['message'] ?? null, $error);
        }
    }

    /**
     * Support mode needs no Passport token (the agent looks the student up by code).
     * Only AURA-AGENT has (will have) it; FREYA-AGENT's contract has no support mode.
     */
    private function assertSupportMode(AgentClient $client): null
    {
        if (! $client->supportEnabled()) {
            $message = $client->agent === Agent::Aura
                ? self::SUPPORT_UNAVAILABLE
                : "{$client->agent->label()} ยังไม่รองรับการถามแทนนักศึกษา";

            throw new UpstreamServiceException($client->agent->engine(), $message, 503, errorCode: 'SUPPORT_UNAVAILABLE');
        }

        return null;
    }

    /**
     * The user's YRU Passport access token. Students and staff both go to the
     * agent (since AURA-AGENT 670a21a staff can search activity rounds and are
     * told personal progress is for students only), so no role filter here.
     */
    private function passportToken(AgentClient $client, User $user): ?string
    {
        if (! $client->enabled()) {
            throw new UpstreamServiceException($client->agent->engine(), AgentClient::UNAVAILABLE, 503, errorCode: 'AGENT_UNAVAILABLE');
        }

        $token = $this->passport->accessTokenFor($user);

        // No Passport login: FREYA-AGENT still answers general registrar rules
        // (guest mode, needs a UUID thread_id, which ours always is).
        if ($token === null && ! $client->agent->allowsGuests()) {
            throw new UpstreamServiceException($client->agent->engine(), self::PASSPORT_REQUIRED, 401, errorCode: 'PASSPORT_REQUIRED');
        }

        return $token;
    }

    /**
     * Load the user's existing session (404 if it belongs to someone else) or
     * prepare an unsaved new one titled after the first question. A session is
     * tied to one subject: the user themself, or the one student asked about.
     */
    private function resolveSession(User $user, ?int $sessionId, string $question, ?ActAs $actAs = null, ?Agent $agent = null): ChatSession
    {
        if ($sessionId !== null) {
            $session = $user->chatSessions()->findOrFail($sessionId);

            abort_unless(
                $session->acting_as_student === $actAs?->studentCode,
                422,
                'ห้องแชทนี้เป็นของนักศึกษาคนอื่น กรุณาเริ่มแชทใหม่',
            );

            abort_unless($agent === null || $session->agent === $agent, 422, self::agentMismatch($session->agent));

            return $session;
        }

        $session = new ChatSession([
            'title' => Str::limit(($actAs ? "[{$actAs->studentCode}] " : '').Str::squish($question), 60),
            'acting_as_student' => $actAs?->studentCode,
            'agent' => $agent ?? Agent::Aura,
        ]);
        $session->user()->associate($user);

        return $session;
    }

    /**
     * Token usage of one answer, as sent to API clients. Every field is null
     * when the engine did not report it (older messages, agents without usage).
     *
     * @return array{prompt_tokens: ?int, completion_tokens: ?int, total_tokens: ?int}
     */
    public static function usage(?array $stats): array
    {
        $prompt = $stats['prompt_tokens'] ?? null;
        $completion = $stats['completion_tokens'] ?? null;

        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt === null && $completion === null ? null : (int) $prompt + (int) $completion,
        ];
    }

    /**
     * @return array{session: ChatSession, question: ChatMessage, message: ChatMessage}
     */
    private function persist(ChatSession $session, string $question, string $answer, array $stats): array
    {
        return DB::transaction(function () use ($session, $question, $answer, $stats) {
            $session->save();

            $questionMessage = $session->messages()->create([
                'role' => MessageRole::User,
                'content' => $question,
            ]);

            $message = $session->messages()->create([
                'role' => MessageRole::Assistant,
                'content' => $answer,
                'metadata' => $stats,
            ]);

            return ['session' => $session, 'question' => $questionMessage, 'message' => $message];
        });
    }
}
