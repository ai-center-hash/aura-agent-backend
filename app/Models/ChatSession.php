<?php

namespace App\Models;

use App\Enums\Agent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['title', 'external_id', 'agent', 'agent_thread_id', 'acting_as_student'])]
class ChatSession extends Model
{
    /**
     * New (unsaved) sessions talk to AURA unless told otherwise; matches the column default.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'agent' => 'aura',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'agent' => Agent::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id');
    }
}
