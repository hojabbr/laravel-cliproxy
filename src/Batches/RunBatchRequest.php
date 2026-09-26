<?php

namespace Hojabbr\CliProxy\Batches;

use Carbon\CarbonImmutable;
use Hojabbr\CliProxy\Batches\Events\BatchRequestFinished;
use Hojabbr\CliProxy\Client;
use Hojabbr\CliProxy\Support\Options;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Runs one request of an emulated batch against the gateway and stores the outcome.
 *
 * A failure worth another attempt (no connection, 408, 409, 429, any 5xx) goes back on the queue
 * after `cliproxy.batches.backoff`, up to `cliproxy.batches.tries` attempts; anything else is the
 * request's answer and is stored as `errored` at once. The request's row is the only state: the
 * job carries nothing but its id, so a retried or duplicated job re-reads what is true and a
 * finished row is never run twice.
 */
class RunBatchRequest implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(public int $requestId)
    {
        $this->tries = max(1, Options::int('cliproxy.batches.tries', 5));
        $this->timeout = max(1, Options::int('cliproxy.batches.timeout', 600));
        $this->afterCommit = true;
    }

    public function handle(Client $client): void
    {
        $request = BatchRequest::query()->find($this->requestId);

        if ($request === null || $request->isFinished()) {
            return;
        }

        if ($request->expires_at->isPast()) {
            $this->finish($request, BatchRequestStatus::Expired);

            return;
        }

        $request->forceFill([
            'status' => BatchRequestStatus::Running,
            'attempts' => $request->attempts + 1,
            'started_at' => $request->started_at ?? CarbonImmutable::now(),
        ])->save();

        try {
            $response = $client->send(Options::string('cliproxy.batches.protocol', 'chat-completions'), $request->params, max(1, $this->timeout - 15));
        } catch (ConnectionException $failure) {
            $this->retryOrFail($request, 'connection_error', $failure->getMessage(), null);

            return;
        }

        if ($response->successful()) {
            $request->forceFill([
                'result' => (array) $response->json(),
                'model' => is_string($response->json('model')) ? $response->json('model') : null,
                'http_status' => $response->status(),
            ]);

            $this->finish($request, BatchRequestStatus::Succeeded);

            return;
        }

        if (self::isRetryable($response)) {
            $this->retryOrFail($request, self::errorType($response), self::errorMessage($response), $response->status());

            return;
        }

        $this->fail($request, self::errorType($response), self::errorMessage($response), $response->status());
    }

    /**
     * An exception this job did not expect, after its last attempt: the request errors rather
     * than sitting in `running` forever.
     */
    public function failed(?Throwable $exception): void
    {
        $request = BatchRequest::query()->find($this->requestId);

        if ($request === null || $request->isFinished()) {
            return;
        }

        $this->fail($request, 'job_failed', (string) $exception?->getMessage(), null);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return Options::ints('cliproxy.batches.backoff', 30);
    }

    protected function retryOrFail(BatchRequest $request, string $type, string $message, ?int $status): void
    {
        if ($this->attempts() >= $this->tries) {
            $this->fail($request, $type, $message, $status);

            return;
        }

        $request->forceFill([
            'status' => BatchRequestStatus::Queued,
            'error_type' => $type,
            'error_message' => $message,
            'http_status' => $status,
        ])->save();

        $backoff = $this->backoff();

        $this->release($backoff[min($this->attempts(), count($backoff)) - 1] ?? 30);
    }

    protected function fail(BatchRequest $request, string $type, string $message, ?int $status): void
    {
        $request->forceFill([
            'error_type' => $type,
            'error_message' => $message,
            'http_status' => $status,
        ]);

        $this->finish($request, BatchRequestStatus::Errored);
    }

    protected function finish(BatchRequest $request, BatchRequestStatus $status): void
    {
        $request->forceFill(['status' => $status, 'ended_at' => CarbonImmutable::now()])->save();

        event(new BatchRequestFinished($request));

        app(Batches::class)->announceIfEnded($request->batch_id);
    }

    protected static function isRetryable(Response $response): bool
    {
        return in_array($response->status(), [408, 409, 429], true) || $response->serverError();
    }

    protected static function errorType(Response $response): string
    {
        $type = $response->json('error.type');

        return is_string($type) && $type !== '' ? $type : 'http_'.$response->status();
    }

    protected static function errorMessage(Response $response): string
    {
        $message = $response->json('error.message') ?? $response->json('error');

        return is_string($message) && $message !== '' ? $message : mb_substr($response->body(), 0, 2000);
    }
}
