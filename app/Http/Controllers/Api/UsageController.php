<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Token usage totals, summed from ai_requests (one row per chat question).
 * Open WebUI background tasks (title, tags...) are not counted. Questions
 * from before AURA-AGENT reported tokens have none, hence questions_with_usage.
 */
class UsageController extends Controller
{
    private const DEFAULT_DAYS = 30;

    private const MAX_DAYS = 366;

    private const SUMS = 'COUNT(*) as questions, COUNT(prompt_tokens) as questions_with_usage, '
        .'COALESCE(SUM(prompt_tokens), 0) as prompt_tokens, COALESCE(SUM(completion_tokens), 0) as completion_tokens, '
        .'COALESCE(SUM(prompt_tokens), 0) + COALESCE(SUM(completion_tokens), 0) as total_tokens';

    /**
     * GET /api/me/usage — the signed-in user's own totals and a per-day breakdown.
     */
    public function me(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $questions = $this->questions($from, $to)->where('user_id', $request->user()->id);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $this->totals(clone $questions),
            'daily' => $this->daily(clone $questions),
        ]);
    }

    /**
     * GET /api/admin/usage — everyone's totals, per day (group_by=day, default)
     * or per user (group_by=user, busiest first, paginated).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'group_by' => ['nullable', Rule::in(['day', 'user'])],
            'user_id' => ['nullable', 'integer'],
            'username' => ['nullable', 'string', 'max:64'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        [$from, $to] = $this->range($request);

        $questions = $this->questions($from, $to)
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['username'] ?? null, fn ($q, $username) => $q->whereRelation('user', 'username', $username));

        $body = [
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'totals' => $this->totals(clone $questions),
        ];

        if (($filters['group_by'] ?? 'day') === 'day') {
            return response()->json([...$body, 'daily' => $this->daily(clone $questions)]);
        }

        $page = (clone $questions)
            ->select('user_id')
            ->selectRaw(self::SUMS)
            ->groupBy('user_id')
            ->orderByDesc('total_tokens')
            ->orderByDesc('questions')
            ->paginate($filters['per_page'] ?? 50)
            ->withQueryString();

        $users = User::whereIn('id', $page->pluck('user_id')->filter())->get(['id', 'username', 'name', 'email'])->keyBy('id');

        return response()->json([
            ...$body,
            'users' => $page->getCollection()->map(fn ($row) => [
                'user' => $users->get($row->user_id)?->only(['id', 'username', 'name', 'email']),
                ...self::numbers($row),
            ]),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'links' => ['prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()],
        ]);
    }

    /**
     * from / to are calendar days (Y-m-d, inclusive); rows are stored in app time (Asia/Bangkok).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $to = $request->filled('to') ? Carbon::parse($request->input('to')) : today();
        $from = $request->filled('from') ? Carbon::parse($request->input('from')) : $to->copy()->subDays(self::DEFAULT_DAYS - 1);

        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            throw ValidationException::withMessages(['from' => 'ช่วงวันที่ต้องไม่เกิน '.self::MAX_DAYS.' วัน']);
        }

        return [$from->startOfDay(), $to->endOfDay()];
    }

    private function questions(Carbon $from, Carbon $to): Builder
    {
        return AiRequest::query()
            ->where('is_task', false)
            ->whereBetween('created_at', [$from, $to]);
    }

    /**
     * @return array{questions: int, questions_with_usage: int, prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    private function totals(Builder $questions): array
    {
        return self::numbers($questions->selectRaw(self::SUMS)->toBase()->first());
    }

    /**
     * Days with at least one question, oldest first.
     *
     * @return list<array<string, int|string>>
     */
    private function daily(Builder $questions): array
    {
        return $questions
            ->selectRaw('DATE(created_at) as date, '.self::SUMS)
            ->groupBy('date')
            ->orderBy('date')
            ->toBase()
            ->get()
            ->map(fn ($row) => ['date' => (string) $row->date, ...self::numbers($row)])
            ->all();
    }

    /**
     * MySQL returns SUM/COUNT as strings.
     *
     * @return array{questions: int, questions_with_usage: int, prompt_tokens: int, completion_tokens: int, total_tokens: int}
     */
    private static function numbers(object $row): array
    {
        return [
            'questions' => (int) $row->questions,
            'questions_with_usage' => (int) $row->questions_with_usage,
            'prompt_tokens' => (int) $row->prompt_tokens,
            'completion_tokens' => (int) $row->completion_tokens,
            'total_tokens' => (int) $row->total_tokens,
        ];
    }
}
