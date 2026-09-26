<?php

namespace Hojabbr\CliProxy\Commands\Probe;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

/**
 * The probe's agent: fixed instructions, a small output ceiling and optional tools. The ceiling
 * matters because an agent that declares none is sent the provider's own maximum.
 *
 * @internal
 */
final class ProbeAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * @param  list<Tool>  $tools
     */
    public function __construct(
        private string $rules,
        private int $ceiling,
        private array $tools = [],
    ) {}

    public function instructions(): string
    {
        return $this->rules;
    }

    public function maxTokens(): int
    {
        return $this->ceiling;
    }

    /**
     * @return list<Tool>
     */
    public function tools(): iterable
    {
        return $this->tools;
    }
}
