<?php

namespace Hojabbr\CliProxy\Commands\Probe;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * The probe's structured-output agent: one integer field.
 *
 * @internal
 */
final class ProbeSchemaAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): string
    {
        return 'Answer the arithmetic question.';
    }

    public function maxTokens(): int
    {
        return 256;
    }

    public function schema(JsonSchema $schema): array
    {
        return ['answer' => $schema->integer()->required()];
    }
}
