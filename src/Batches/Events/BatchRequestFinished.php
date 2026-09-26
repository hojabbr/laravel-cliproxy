<?php

namespace Hojabbr\CliProxy\Batches\Events;

use Hojabbr\CliProxy\Batches\BatchRequest;
use Illuminate\Foundation\Events\Dispatchable;

class BatchRequestFinished
{
    use Dispatchable;

    public function __construct(public BatchRequest $request) {}
}
