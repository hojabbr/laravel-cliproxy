<?php

namespace Hojabbr\CliProxy\Exceptions;

use RuntimeException;

class ManagementUnavailable extends RuntimeException
{
    public static function missingKey(): self
    {
        return new self('The gateway management key is not set (cliproxy.management_key).');
    }
}
