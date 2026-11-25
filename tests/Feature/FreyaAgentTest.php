<?php

namespace Tests\Feature;

use App\Enums\Agent;
use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FreyaAgentTest extends TestCase
{
    use RefreshDatabase;

    private const AURA = 'http://aura.test';

    private const FREYA = 'http://freya.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.aura_agent' => ['base_url' => self::AURA, 'service_key' => 'aura-key', 'support_key' => null, 'timeout' => 5],
            'services.freya_agent' => ['base_url' => self::FREYA, 'service_key' => 'freya-key', 'timeout' => 5],
        ]);
    }

    public function test_new_session_with_agent_freya_is_answered_by_freya(): void
    {
        $this->fakeAgents();
        Sanctum::actingAs($user = $this->userWithPassport());

        $response = $this->postJson('/api/chat', ['message' => 'เทอมนี้ลงกี่หน่วยกิต', 'agent' => 'freya'])
            ->assertCreated()
            ->assertJsonPath('agent', 'freya')
            ->assertJsonPath('message.content', 'เทอมนี้ลงทะเบียน **13 หน่วยกิต** ค่ะ');

        $session = ChatSession::findOrFail($response->json('session_id'));
        $this->assertSame(Agent::Freya, $session->agent);
        $this->assertSame('freya_agent', $session->messages()->latest('id')->first()->metadata['engine']);

        Http::assertSent(fn (Request $r) => $r->url() === self::FREYA.'/v1/chat'
            && $r->header('X-Service-Key') === ['freya-key']
            && $r->header('Authorization') === ['Bearer passport-token']
            && $r['thread_id'] === $session->agent_thread_id);
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::AURA));
        $this->assertSame($user->id, $session->user_id);
    }

    public function test_default_agent_is_still_aura(): void
    {
        $this->fakeAgents();
        Sanctum::actingAs($this->userWithPassport());

        $this->postJson('/api/chat', ['message' => 'มีกิจกรรมอะไรบ้าง'])
            ->assertCreated()
            ->assertJsonPath('agent', 'aura');

        Http::assertSent(fn (Request $r) => $r->url() === self::AURA.'/v1/chat');
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::FREYA));
    }

    public function test_user_without_passport_asks_freya_as_guest(): void
    {
        $this->fakeAgents();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/chat', ['message' => 'ลาออกต้องทำอย่างไร', 'agent' => 'freya'])->assertCreated();

        Http::assertSent(fn (Request $r) => $r->url() === self::FREYA.'/v1/chat'
            && ! $r->hasHeader('Authorization')
            && preg_match('/^[0-9a-f-]{36}$/', $r['thread_id']) === 1);
    }

    public function test_existing_session_cannot_switch_agent(): void
    {
        $this->fakeAgents();
        Sanctum::actingAs($user = $this->userWithPassport());
        $session = $user->chatSessions()->create(['title' => 'old', 'agent' => Agent::Aura]);

        $this->postJson('/api/chat', ['message' => 'เกรดเทอมนี้', 'session_id' => $session->id, 'agent' => 'freya'])
            ->assertStatus(422);

        $this->postJson('/api/chat/stream', ['message' => 'เกรดเทอมนี้', 'session_id' => $session->id, 'agent' => 'freya'])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_unknown_agent_is_rejected(): void
    {
        Sanctum::actingAs($this->userWithPassport());

        $this->postJson('/api/chat', ['message' => 'hi', 'agent' => 'odin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('agent');
    }

    public function test_deleting_a_freya_session_forgets_the_thread_at_freya(): void
    {
        Http::fake(['*' => Http::response(status: 204)]);
        Sanctum::actingAs($user = $this->userWithPassport());
        $session = $user->chatSessions()->create([
            'title' => 'freya chat',
            'agent' => Agent::Freya,
            'agent_thread_id' => $thread = '3f1c2a10-5b7e-4c9d-8a21-0f6e9b2d4c11',
        ]);

        $this->deleteJson("/api/chat/sessions/{$session->id}")->assertNoContent();

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::FREYA.'/v1/chat/'.$thread);
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), self::AURA));
    }

    private function userWithPassport(): User
    {
        $user = User::factory()->create();
        $user->forceFill([
            'passport_access_token' => 'passport-token',
            'passport_token_expires_at' => now()->addHour(),
        ])->save();

        return $user;
    }

    private function fakeAgents(): void
    {
        $sse = fn (string $thread = 'x') => implode('', [
            "event: status\r\ndata: {\"text\": \"กำลังดูวิชาที่ลงทะเบียนและตารางเรียน...\"}\r\n\r\n",
            "event: token\r\ndata: {\"text\": \"เทอมนี้ลงทะเบียน\"}\r\n\r\n",
            "event: token\r\ndata: {\"text\": \" **13 หน่วยกิต** ค่ะ\"}\r\n\r\n",
            "event: done\r\ndata: {\"thread_id\": \"{$thread}\", \"tools_used\": [\"get_my_enrollment\"], \"latency_ms\": 5100, \"prompt_tokens\": 3300, \"completion_tokens\": 120}\r\n\r\n",
        ]);

        Http::fake([
            self::AURA.'/*' => Http::response($sse(), 200, ['Content-Type' => 'text/event-stream']),
            self::FREYA.'/*' => Http::response($sse(), 200, ['Content-Type' => 'text/event-stream']),
        ]);
    }
}
