<?php

namespace Hojabbr\CliProxy\Batches;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Hojabbr\CliProxy\Batches\Events\BatchEnded;
use Hojabbr\CliProxy\Exceptions\BatchNotFound;
use Hojabbr\CliProxy\Support\Options;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Batches over a gateway that has none: create, retrieve, results, cancel and list, run as one
 * queued call per request in `cliproxy.batches.protocol`.
 *
 * A batch here is a set of rows sharing a `cpb_` id. It costs what the same calls cost one by
 * one, and it finishes as fast as your queue workers drain it. The lifecycle is the familiar
 * one (a status with request counts, then a result per request), so code written against a
 * vendor's batch API submits, polls and collects here the same way.
 */
class Batches
{
    public const string ID_PREFIX = 'cpb_';

    public function __construct(protected Cache $cache) {}

    /**
     * Queue a batch. Each request is `['custom_id' => string, 'params' => array]`, where
     * `params` is a request body for the batches protocol's endpoint (`model`, `messages`, ...).
     *
     * @param  iterable<array{custom_id: string, params: array<string, mixed>}>  $requests
     */
    public function create(iterable $requests): BatchStatus
    {
        $id = self::ID_PREFIX.Str::lower((string) Str::ulid());
        $now = CarbonImmutable::now();
        $expiresAt = $now->addHours(Options::int('cliproxy.batches.expires_after_hours', 24));
        $rows = $this->rows($id, $requests, $now, $expiresAt);

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 100) as $chunk) {
                BatchRequest::query()->insert($chunk);
            }
        });

        $requests = BatchRequest::query()->where('batch_id', $id)->orderBy('id')->get(['id']);

        foreach ($requests as $request) {
            RunBatchRequest::dispatch($request->id)
                ->onConnection(Options::nullableString('cliproxy.batches.connection'))
                ->onQueue(Options::nullableString('cliproxy.batches.queue'));
        }

        return $this->retrieve($id);
    }

    public function retrieve(string $id): BatchStatus
    {
        $requests = BatchRequest::query()
            ->where('batch_id', $id)
            ->get(['status', 'created_at', 'expires_at', 'ended_at', 'canceled_at']);

        $first = $requests->first() ?? throw BatchNotFound::id($id);

        $counts = ['processing' => 0, 'succeeded' => 0, 'errored' => 0, 'canceled' => 0, 'expired' => 0];

        foreach ($requests as $request) {
            match ($request->status) {
                BatchRequestStatus::Queued, BatchRequestStatus::Running => $counts['processing']++,
                BatchRequestStatus::Succeeded => $counts['succeeded']++,
                BatchRequestStatus::Errored => $counts['errored']++,
                BatchRequestStatus::Canceled => $counts['canceled']++,
                BatchRequestStatus::Expired => $counts['expired']++,
            };
        }

        $processing = match (true) {
            $counts['processing'] === 0 => 'ended',
            $requests->contains(fn (BatchRequest $request): bool => $request->canceled_at !== null) => 'canceling',
            default => 'in_progress',
        };

        $endedAt = $requests->map(fn (BatchRequest $request): ?CarbonInterface => $request->ended_at)->filter()->max();

        return new BatchStatus(
            id: $id,
            processingStatus: $processing,
            requestCounts: $counts,
            createdAt: CarbonImmutable::instance($first->created_at),
            expiresAt: CarbonImmutable::instance($first->expires_at),
            endedAt: $processing === 'ended' && $endedAt instanceof CarbonInterface ? CarbonImmutable::instance($endedAt) : null,
        );
    }

    /**
     * Every finished request's outcome, in submission order. Requests still pending are not
     * listed.
     *
     * @return LazyCollection<int, BatchResult>
     */
    public function results(string $id): LazyCollection
    {
        return BatchRequest::query()
            ->where('batch_id', $id)
            ->whereNotIn('status', BatchRequestStatus::pending())
            ->orderBy('id')
            ->lazyById(100)
            ->map(static fn (BatchRequest $request): BatchResult => BatchResult::from($request));
    }

    /**
     * Cancel what has not started. A request already running finishes and keeps its answer.
     */
    public function cancel(string $id): BatchStatus
    {
        $now = CarbonImmutable::now();

        BatchRequest::query()->where('batch_id', $id)->update(['canceled_at' => $now]);

        BatchRequest::query()
            ->where('batch_id', $id)
            ->where('status', BatchRequestStatus::Queued->value)
            ->update(['status' => BatchRequestStatus::Canceled->value, 'ended_at' => $now, 'updated_at' => $now]);

        $this->announceIfEnded($id);

        return $this->retrieve($id);
    }

    /**
     * Dispatch {@see BatchEnded} when nothing in the batch is pending any more, once per batch
     * however many finishers ask at the same moment.
     */
    public function announceIfEnded(string $id): void
    {
        $pending = BatchRequest::query()
            ->where('batch_id', $id)
            ->whereIn('status', BatchRequestStatus::pending())
            ->exists();

        if (! $pending && $this->cache->add("cliproxy:batch:{$id}:ended", true, 86_400)) {
            event(new BatchEnded($id));
        }
    }

    /**
     * The most recent batches, newest first.
     *
     * @return list<BatchStatus>
     */
    public function list(int $limit = 20): array
    {
        $batches = BatchRequest::query()
            ->select('batch_id')
            ->groupBy('batch_id')
            ->orderByRaw('max(id) desc')
            ->limit($limit)
            ->get();

        $statuses = [];

        foreach ($batches as $batch) {
            $statuses[] = $this->retrieve($batch->batch_id);
        }

        return $statuses;
    }

    /**
     * @param  iterable<mixed>  $requests
     * @return list<array<string, mixed>>
     */
    protected function rows(string $id, iterable $requests, CarbonImmutable $now, CarbonImmutable $expiresAt): array
    {
        $rows = [];
        $seen = [];

        foreach ($requests as $request) {
            $customId = is_array($request) ? ($request['custom_id'] ?? null) : null;
            $params = is_array($request) ? ($request['params'] ?? null) : null;

            if (! is_string($customId) || $customId === '' || ! is_array($params)) {
                throw new InvalidArgumentException('Each batch request needs a non-empty string custom_id and an array of params.');
            }

            if (isset($seen[$customId])) {
                throw new InvalidArgumentException("The custom_id [{$customId}] appears twice in one batch.");
            }

            $seen[$customId] = true;
            $rows[] = [
                'batch_id' => $id,
                'custom_id' => $customId,
                'status' => BatchRequestStatus::Queued->value,
                'params' => json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'attempts' => 0,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            throw new InvalidArgumentException('Refusing to create an empty batch.');
        }

        return $rows;
    }
}
