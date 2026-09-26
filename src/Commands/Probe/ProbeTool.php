<?php

namespace Hojabbr\CliProxy\Commands\Probe;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * A tool whose answer the model cannot guess, so a reply carrying it proves the tool ran.
 *
 * @internal
 */
final class ProbeTool implements Tool
{
    public const int NUMBER = 7_391;

    public int $calls = 0;

    public function description(): string
    {
        return 'Returns the secret number.';
    }

    public function handle(Request $request): string
    {
        $this->calls++;

        return (string) self::NUMBER;
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
