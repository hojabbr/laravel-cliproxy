<?php

namespace Hojabbr\CliProxy\Batches;

use Carbon\CarbonInterface;
use Hojabbr\CliProxy\Support\Options;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One request of an emulated batch, and, once it has run, its answer.
 *
 * @property int $id
 * @property string $batch_id
 * @property string $custom_id
 * @property BatchRequestStatus $status
 * @property array<string, mixed> $params
 * @property array<string, mixed>|null $result
 * @property string|null $model
 * @property string|null $error_type
 * @property string|null $error_message
 * @property int|null $http_status
 * @property int $attempts
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $canceled_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $ended_at
 * @property CarbonInterface $created_at
 */
class BatchRequest extends Model
{
    use MassPrunable;

    protected $guarded = [];

    public function getTable(): string
    {
        return Options::string('cliproxy.batches.table', 'cliproxy_batch_requests');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BatchRequestStatus::class,
            'params' => 'array',
            'result' => 'array',
            'http_status' => 'integer',
            'attempts' => 'integer',
            'expires_at' => 'datetime',
            'canceled_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * Finished requests older than `cliproxy.batches.prune_after_days`, for `model:prune`.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()
            ->whereNotNull('ended_at')
            ->where('ended_at', '<', now()->subDays(Options::int('cliproxy.batches.prune_after_days', 7)));
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }
}
