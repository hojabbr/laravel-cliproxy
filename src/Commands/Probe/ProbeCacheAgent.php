<?php

namespace Hojabbr\CliProxy\Commands\Probe;

use Laravel\Ai\Attributes\CacheInstructions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * The probe's caching agent: long fixed instructions, marked cacheable for the `messages`
 * protocol; `chat-completions` upstreams that cache do so on the prefix by themselves.
 *
 * @internal
 */
#[CacheInstructions]
final class ProbeCacheAgent implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return ProbeFiles::longPrefix();
    }

    public function maxTokens(): int
    {
        return 16;
    }
}
