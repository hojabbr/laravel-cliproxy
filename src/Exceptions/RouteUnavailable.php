<?php

namespace Hojabbr\CliProxy\Exceptions;

use RuntimeException;

class RouteUnavailable extends RuntimeException
{
    public static function undefined(string $name): self
    {
        return new self("No route named [{$name}] in cliproxy.routes.");
    }

    public static function switchedOff(string $model): self
    {
        return new self("The route to [{$model}] is switched off and names no fallback.");
    }
}
