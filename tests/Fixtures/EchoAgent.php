<?php

namespace Hojabbr\CliProxy\Tests\Fixtures;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

final class EchoAgent implements Agent, HasProviderOptions
{
    use Promptable;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(private array $options = []) {}

    public function instructions(): string
    {
        return 'Answer briefly.';
    }

    public function maxTokens(): int
    {
        return 128;
    }

    public function providerOptions(Lab|string $provider): array
    {
        return $this->options;
    }
}
