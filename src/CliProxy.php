<?php

namespace Hojabbr\CliProxy;

use Hojabbr\CliProxy\Batches\Batches;
use Hojabbr\CliProxy\Routing\Route;
use Hojabbr\CliProxy\Routing\Router;
use Hojabbr\CliProxy\Support\Options;
use Illuminate\Contracts\Container\Container;

/**
 * The package's front door, behind the `CliProxy` facade.
 *
 * Collaborators are resolved from the container on each call, never held, so `CliProxy::fake()`
 * and any rebinding in tests take effect at once, and nothing request-scoped outlives a request
 * under Octane.
 */
class CliProxy
{
    public function __construct(protected Container $app) {}

    public function client(): Client
    {
        return $this->app->make(Client::class);
    }

    public function readiness(): Readiness
    {
        return $this->app->make(Readiness::class);
    }

    public function management(): Management
    {
        return $this->app->make(Management::class);
    }

    public function batches(): Batches
    {
        return $this->app->make(Batches::class);
    }

    /**
     * The laravel/ai provider name the gateway is registered under.
     */
    public function provider(): string
    {
        return Options::provider();
    }

    /**
     * A route declared under `cliproxy.routes`.
     */
    public function route(string $name): Route
    {
        return $this->app->make(Router::class)->route($name);
    }

    /**
     * An ad hoc route: a gateway model, what to fall back to, and whether the gateway is on.
     *
     * @param  array<string, string>  $fallback  Provider name => model.
     */
    public function routeTo(string $model, array $fallback = [], bool $enabled = true): Route
    {
        return $this->app->make(Router::class)->to($model, $fallback, $enabled);
    }

    public function isUp(): bool
    {
        return $this->readiness()->isUp();
    }

    public function serves(string $model): bool
    {
        return $this->readiness()->serves($model);
    }

    /**
     * @return list<string>
     */
    public function models(): array
    {
        return $this->readiness()->models();
    }
}
