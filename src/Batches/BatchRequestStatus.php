<?php

namespace Hojabbr\CliProxy\Batches;

enum BatchRequestStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Errored = 'errored';
    case Canceled = 'canceled';
    case Expired = 'expired';

    public function isFinished(): bool
    {
        return ! in_array($this, [self::Queued, self::Running], true);
    }

    /**
     * @return list<string>
     */
    public static function pending(): array
    {
        return [self::Queued->value, self::Running->value];
    }
}
