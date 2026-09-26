<?php

namespace Hojabbr\CliProxy\Testing;

use Hojabbr\CliProxy\Readiness;

/**
 * Readiness from a fixed answer, for tests. Change it mid-test with `down()`, `up()` and `serve()`.
 */
class FakeReadiness extends Readiness
{
    /**
     * @param  list<string>  $fakeModels  The models the fake gateway serves.
     */
    public function __construct(protected array $fakeModels = [], protected bool $fakeUp = true) {}

    public function state(): array
    {
        return [
            'up' => $this->fakeUp,
            'models' => $this->fakeUp ? $this->fakeModels : [],
            'error' => $this->fakeUp ? null : 'The fake gateway is down.',
        ];
    }

    public function forget(): void {}

    public function down(): static
    {
        $this->fakeUp = false;

        return $this;
    }

    public function up(): static
    {
        $this->fakeUp = true;

        return $this;
    }

    /**
     * @param  list<string>  $models
     */
    public function serve(array $models): static
    {
        $this->fakeModels = $models;

        return $this;
    }
}
