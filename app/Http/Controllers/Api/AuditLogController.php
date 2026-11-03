<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Audit trail for admins: logins, logouts, token issuance, 403s, 429s,
 * act-as and deletions. The same rows as the console's log page.
 */
class AuditLogController extends Controller
{
    /**
     * GET /api/admin/audit-logs — newest first.
     * Filters: event ("auth.logout", or a prefix ending in a dot: "auth."),
     * user_id, username, from / to (Y-m-d, inclusive), per_page (default 50, max 100).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'event' => ['nullable', 'string', 'max:64'],
            'user_id' => ['nullable', 'integer'],
            'username' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $event = $filters['event'] ?? null;

        $logs = AuditLog::with('user:id,username,name,email')
            ->when($event !== null && str_ends_with($event, '.'), fn ($q) => $q->where('event', 'like', addcslashes($event, '%_').'%'))
            ->when($event !== null && ! str_ends_with($event, '.'), fn ($q) => $q->where('event', $event))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('user_id', $id))
            ->when($filters['username'] ?? null, fn ($q, $username) => $q->whereRelation('user', 'username', $username))
            ->when($filters['from'] ?? null, fn ($q, $day) => $q->where('created_at', '>=', Carbon::parse($day)->startOfDay()))
            ->when($filters['to'] ?? null, fn ($q, $day) => $q->where('created_at', '<=', Carbon::parse($day)->endOfDay()))
            ->latest('id')
            ->paginate($filters['per_page'] ?? 50)
            ->withQueryString();

        return AuditLogResource::collection($logs);
    }
}
