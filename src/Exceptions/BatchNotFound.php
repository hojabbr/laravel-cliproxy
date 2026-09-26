<?php

namespace Hojabbr\CliProxy\Exceptions;

use RuntimeException;

class BatchNotFound extends RuntimeException
{
    public static function id(string $id): self
    {
        return new self("No batch [{$id}].");
    }
}
