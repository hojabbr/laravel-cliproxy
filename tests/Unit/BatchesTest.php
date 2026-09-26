<?php

use Hojabbr\CliProxy\Batches\BatchRequest;
use Hojabbr\CliProxy\Batches\BatchRequestStatus;
use Hojabbr\CliProxy\Batches\Events\BatchEnded;
use Hojabbr\CliProxy\Batches\Events\BatchRequestFinished;
use Hojabbr\CliProxy\Batches\RunBatchRequest;
use Hojabbr\CliProxy\Exceptions\BatchNotFound;
use Hojabbr\CliProxy\Facades\CliProxy;
use Hojabbr\CliProxy\Tests\Fixtures\Messages;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * @return list<array{custom_id: string, params: array<string, mixed>}>
 */
function requests(int $count): array
{
    return array_map(static fn (int $i): array => [
        'custom_id' => "item-{$i}",
        'params' => ['model' => 'alias-a', 'messages' => [['role' => 'user', 'content' => "Say {$i}."]]],
    ], range(1, $count));
}

function runQueued(int $requestId): RunBatchRequest
{
    $job = (new RunBatchRequest($requestId))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

it('runs every request and ends the batch once', function (): void {
    Event::fake([BatchEnded::class, BatchRequestFinished::class]);
    Http::fake(['gateway.test/v1/chat/completions' => Http::sequence()
        ->push(Messages::chat('one', 'served-a'))
        ->push(Messages::chat('two', 'served-a'))]);

    $status = CliProxy::batches()->create(requests(2));

    expect($status->id)->toStartWith('cpb_')
        ->and($status->hasEnded())->toBeTrue()
        ->and($status->requestCounts)->toBe(['processing' => 0, 'succeeded' => 2, 'errored' => 0, 'canceled' => 0, 'expired' => 0]);

    $results = CliProxy::batches()->results($status->id)->all();

    expect(array_map(fn ($result) => [$result->customId, $result->type, $result->text()], $results))
        ->toBe([['item-1', 'succeeded', 'one'], ['item-2', 'succeeded', 'two']])
        ->and(BatchRequest::query()->pluck('model')->all())->toBe(['served-a', 'served-a']);

    Event::assertDispatchedTimes(BatchRequestFinished::class, 2);
    Event::assertDispatchedTimes(BatchEnded::class, 1);
    Http::assertSent(fn (Request $request): bool => $request['stream'] === false && $request->header('Authorization') === ['Bearer client-key']);
});

it('posts each request in the batches protocol', function (): void {
    config(['cliproxy.batches.protocol' => 'messages']);
    Http::fake(['gateway.test/v1/messages' => Http::response(Messages::messages('from messages'))]);

    $status = CliProxy::batches()->create(requests(1));

    expect(CliProxy::batches()->results($status->id)->first()->text())->toBe('from messages');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://gateway.test/v1/messages');
});

it('puts a retryable failure back on the queue with the configured backoff', function (int $status): void {
    Queue::fake();
    Http::fake(['gateway.test/*' => Http::response(['error' => ['type' => 'overloaded', 'message' => 'busy']], $status)]);

    $batch = CliProxy::batches()->create(requests(1));
    $request = BatchRequest::query()->firstOrFail();

    runQueued($request->id)->assertReleased(30);

    expect($request->refresh()->status)->toBe(BatchRequestStatus::Queued)
        ->and($request->attempts)->toBe(1)
        ->and($request->error_type)->toBe('overloaded')
        ->and(CliProxy::batches()->retrieve($batch->id)->processingStatus)->toBe('in_progress');
})->with([429, 500, 503]);

it('stores a failure that is not worth another attempt as errored at once', function (): void {
    Http::fake(['gateway.test/*' => Http::response(['error' => ['type' => 'invalid_request_error', 'message' => 'bad model']], 400)]);

    $status = CliProxy::batches()->create(requests(1));
    $result = CliProxy::batches()->results($status->id)->first();

    expect($result->type)->toBe('errored')
        ->and($result->error)->toBe(['type' => 'invalid_request_error', 'message' => 'bad model', 'status' => 400])
        ->and($result->text())->toBeNull();
});

it('errors a request whose retries are spent', function (): void {
    Queue::fake();
    Http::fake(['gateway.test/*' => Http::response('upstream gone', 502)]);

    CliProxy::batches()->create(requests(1));
    $request = BatchRequest::query()->firstOrFail();

    $job = (new RunBatchRequest($request->id))->withFakeQueueInteractions();
    $job->tries = 1;
    app()->call([$job, 'handle']);

    $job->assertNotReleased();
    expect($request->refresh()->status)->toBe(BatchRequestStatus::Errored)
        ->and($request->error_type)->toBe('http_502')
        ->and($request->error_message)->toBe('upstream gone');
});

it('cancels what has not started and ends the batch', function (): void {
    Queue::fake();
    Event::fake([BatchEnded::class]);

    $batch = CliProxy::batches()->create(requests(3));
    $status = CliProxy::batches()->cancel($batch->id);

    expect($status->hasEnded())->toBeTrue()
        ->and($status->requestCounts['canceled'])->toBe(3);

    runQueued(BatchRequest::query()->firstOrFail()->id);

    Http::assertNothingSent();
    Event::assertDispatchedTimes(BatchEnded::class, 1);
});

it('reports a batch as canceling while a request it cannot stop is running', function (): void {
    Queue::fake();

    $batch = CliProxy::batches()->create(requests(2));
    BatchRequest::query()->where('custom_id', 'item-1')->update(['status' => BatchRequestStatus::Running->value]);

    expect(CliProxy::batches()->cancel($batch->id)->processingStatus)->toBe('canceling');
});

it('expires a request its batch outlived', function (): void {
    Queue::fake();

    CliProxy::batches()->create(requests(1));
    $request = BatchRequest::query()->firstOrFail();
    $request->forceFill(['expires_at' => now()->subMinute()])->save();

    runQueued($request->id);

    expect($request->refresh()->status)->toBe(BatchRequestStatus::Expired);
    Http::assertNothingSent();
});

it('refuses a batch it could not run', function (array $requests, string $message): void {
    expect(fn () => CliProxy::batches()->create($requests))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'empty' => [[], 'empty batch'],
    'duplicate id' => [[['custom_id' => 'a', 'params' => []], ['custom_id' => 'a', 'params' => []]], 'appears twice'],
    'no params' => [[['custom_id' => 'a']], 'custom_id and an array of params'],
]);

it('lists batches newest first and refuses an unknown one', function (): void {
    Queue::fake();

    $first = CliProxy::batches()->create(requests(1));
    $second = CliProxy::batches()->create(requests(1));

    expect(array_map(fn ($status) => $status->id, CliProxy::batches()->list()))->toBe([$second->id, $first->id])
        ->and(fn () => CliProxy::batches()->retrieve('cpb_missing'))->toThrow(BatchNotFound::class);
});

it('prunes finished requests after the configured days', function (): void {
    Http::fake(['gateway.test/*' => Http::response(Messages::chat())]);

    CliProxy::batches()->create(requests(2));
    BatchRequest::query()->where('custom_id', 'item-1')->update(['ended_at' => now()->subDays(8)]);

    $this->artisan('model:prune', ['--model' => [BatchRequest::class]])->assertSuccessful();

    expect(BatchRequest::query()->pluck('custom_id')->all())->toBe(['item-2']);
});
