<?php

namespace App\Services\Agent;

use App\Enums\Agent;

/**
 * One AgentClient per agent, built from config('services.<agent>_agent').
 */
class AgentRegistry
{
    /** @var array<string, AgentClient> */
    private array $clients = [];

    /**
     * @param  array<string, array<string, mixed>>  $config  keyed by Agent::engine()
     */
    public function __construct(private readonly array $config) {}

    public function for(Agent $agent): AgentClient
    {
        return $this->clients[$agent->value] ??= new AgentClient($agent, $this->config[$agent->engine()] ?? []);
    }
}
