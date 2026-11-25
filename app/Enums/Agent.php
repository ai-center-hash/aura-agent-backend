<?php

namespace App\Enums;

/**
 * The agents a conversation can be held with. Both speak the same
 * /v1/chat contract (docs/01-api-contract.md); a chat session is bound to
 * one agent for good, since the agent keeps the conversation's memory.
 */
enum Agent: string
{
    // Student activities (Activity API).
    case Aura = 'aura';
    // Registrar: enrolment, grades, graduation check, fees (registrar DB).
    case Freya = 'freya';

    public function label(): string
    {
        return match ($this) {
            self::Aura => 'AURA-AGENT',
            self::Freya => 'FREYA-AGENT',
        };
    }

    /**
     * Key in config('services.*'), also stored as ai_requests.engine and
     * chat_messages.metadata.engine.
     */
    public function engine(): string
    {
        return $this->value.'_agent';
    }

    /**
     * Model id shown in Open WebUI's model picker.
     */
    public function modelId(): string
    {
        return (string) config("openwebui.models.{$this->value}", $this->value.'-agent');
    }

    /**
     * Open WebUI's "model" field → agent. Unknown or empty = AURA (the original model).
     */
    public static function fromModelId(?string $model): self
    {
        foreach (self::cases() as $agent) {
            if ($model !== null && $model === $agent->modelId()) {
                return $agent;
            }
        }

        return self::Aura;
    }

    /**
     * Freya answers general registrar rules to anyone, so a user without a
     * Passport login may still ask it (no Authorization header is sent).
     */
    public function allowsGuests(): bool
    {
        return $this === self::Freya;
    }
}
