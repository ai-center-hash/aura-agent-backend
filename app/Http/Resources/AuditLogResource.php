<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AuditLog */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'user' => $this->user ? $this->user->only(['id', 'username', 'name', 'email']) : null,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'metadata' => $this->metadata,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at,
        ];
    }
}
