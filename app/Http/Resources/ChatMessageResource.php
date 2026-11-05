<?php

namespace App\Http\Resources;

use App\Enums\MessageRole;
use App\Services\Chat\ChatService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ChatMessage */
class ChatMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->session_id,
            'role' => $this->role,
            'content' => $this->content,
            'tools_used' => $this->metadata['tools_used'] ?? [],
            // Assistant answers only; null for the user's own messages.
            'usage' => $this->role === MessageRole::Assistant ? ChatService::usage($this->metadata) : null,
            'created_at' => $this->created_at,
        ];
    }
}
