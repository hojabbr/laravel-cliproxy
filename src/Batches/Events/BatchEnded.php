<?php

namespace Hojabbr\CliProxy\Batches\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Every request of the batch has finished. Dispatched once per batch, by whichever request
 * finished last, guarded by an atomic cache add.
 */
class BatchEnded
{
    use Dispatchable;

    public function __construct(public string $batchId) {}
}
