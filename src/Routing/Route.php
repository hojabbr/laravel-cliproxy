<?php

namespace Hojabbr\CliProxy\Routing;

/**
 * Where one call goes: the gateway, its fallback, or both in order.
 *
 * `providers()` is the shape laravel/ai's `prompt(provider: ...)` and `stream(provider: ...)`
 * take, so the package's failover does the rest: a gateway failure it treats as transient moves
 * the call to the next provider in the map.
 */
final readonly class Route
{
    /**
     * @param  array<string, string>  $providers  Provider name => model, in the order to try them.
     */
    public function __construct(
        public array $providers,
        public bool $usesGateway,
    ) {}

    /**
     * @return array<string, string>
     */
    public function providers(): array
    {
        return $this->providers;
    }

    public function usesGateway(): bool
    {
        return $this->usesGateway;
    }

    /**
     * The provider the call tries first.
     */
    public function provider(): string
    {
        return (string) array_key_first($this->providers);
    }

    /**
     * The model the call tries first.
     */
    public function model(): string
    {
        return $this->providers[$this->provider()];
    }
}
