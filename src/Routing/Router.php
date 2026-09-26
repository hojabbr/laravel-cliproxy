<?php

namespace Hojabbr\CliProxy\Routing;

use Hojabbr\CliProxy\Exceptions\RouteUnavailable;
use Hojabbr\CliProxy\Readiness;
use Hojabbr\CliProxy\Support\Options;

/**
 * Decides, per call, whether the gateway takes it.
 *
 * The gateway is tried first only when its switch is on AND it currently lists the model; a
 * fallback, when given, follows it. A route that is on for a model the gateway does not list,
 * with nothing to fall back to, still goes to the gateway, so the caller sees the gateway's own
 * error rather than a guess of ours.
 */
class Router
{
    public function __construct(protected Readiness $readiness) {}

    /**
     * A route declared under `cliproxy.routes`.
     */
    public function route(string $name): Route
    {
        $model = Options::nullableString("cliproxy.routes.{$name}.model") ?? throw RouteUnavailable::undefined($name);

        return $this->to(
            $model,
            Options::stringMap(config("cliproxy.routes.{$name}.fallback")),
            Options::bool("cliproxy.routes.{$name}.enabled"),
        );
    }

    /**
     * An ad hoc route: a gateway model, what to fall back to, and whether the gateway is on.
     *
     * @param  array<string, string>  $fallback  Provider name => model.
     */
    public function to(string $model, array $fallback = [], bool $enabled = true): Route
    {
        $gateway = Options::provider();

        unset($fallback[$gateway]);

        if (! $enabled) {
            return $fallback === []
                ? throw RouteUnavailable::switchedOff($model)
                : new Route($fallback, false);
        }

        if ($fallback !== [] && ! $this->readiness->serves($model)) {
            return new Route($fallback, false);
        }

        return new Route([$gateway => $model] + $fallback, true);
    }
}
