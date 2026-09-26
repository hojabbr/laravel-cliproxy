<?php

use Hojabbr\CliProxy\Ai\CliProxyProvider;
use Hojabbr\CliProxy\Ai\Gateways\ChatCompletionsGateway;
use Hojabbr\CliProxy\Ai\Gateways\MessagesGateway;
use Hojabbr\CliProxy\Tests\Fixtures\EchoAgent;
use Hojabbr\CliProxy\Tests\Fixtures\Messages;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Document;

function useMessagesProtocol(): void
{
    config(['ai.providers.cliproxy' => ['driver' => 'cliproxy', 'protocol' => 'messages']]);
    forgetProviders();
}

/**
 * laravel/ai caches a provider once resolved, and its facade caches the manager.
 */
function forgetProviders(): void
{
    app()->forgetInstance(AiManager::class);
    Ai::clearResolvedInstances();
}

it('registers the cliproxy provider with the gateway url and key read at resolution', function (): void {
    config(['cliproxy.url' => 'http://late.test/', 'cliproxy.key' => 'late-key']);

    $provider = Ai::textProvider('cliproxy');

    expect($provider)->toBeInstanceOf(CliProxyProvider::class)
        ->and($provider->textGateway())->toBeInstanceOf(ChatCompletionsGateway::class)
        ->and($provider->additionalConfiguration()['url'])->toBe('http://late.test/v1')
        ->and($provider->providerCredentials()['key'])->toBe('late-key');
});

it('keeps an ai.providers entry the application declared itself', function (): void {
    config(['ai.providers.cliproxy' => ['driver' => 'cliproxy', 'url' => 'http://mine.test/v1', 'key' => 'mine']]);
    forgetProviders();

    expect(Ai::textProvider('cliproxy')->additionalConfiguration()['url'])->toBe('http://mine.test/v1');
});

it('refuses a protocol it does not speak', function (): void {
    config(['ai.providers.cliproxy' => ['driver' => 'cliproxy', 'protocol' => 'carrier-pigeon']]);
    forgetProviders();

    expect(fn () => Ai::textProvider('cliproxy'))->toThrow(InvalidArgumentException::class, 'carrier-pigeon');
});

it('sends a prompt over chat completions by default, with the client key', function (): void {
    Http::fake(['gateway.test/v1/chat/completions' => Http::response(Messages::chat('pong'))]);

    expect((new EchoAgent)->prompt('Say pong.', provider: 'cliproxy', model: 'my-alias')->text)->toBe('pong');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://gateway.test/v1/chat/completions'
        && $request->header('Authorization') === ['Bearer client-key']
        && $request['model'] === 'my-alias');
});

it('speaks the messages protocol when the entry asks for it', function (): void {
    useMessagesProtocol();
    Http::fake(['gateway.test/v1/messages' => Http::response(Messages::messages('pong'))]);

    expect(Ai::textProvider('cliproxy')->textGateway())->toBeInstanceOf(MessagesGateway::class)
        ->and((new EchoAgent)->prompt('Say pong.', provider: 'cliproxy', model: 'my-alias')->text)->toBe('pong');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'http://gateway.test/v1/messages'
        && $request->header('x-api-key') === ['client-key']
        && $request['max_tokens'] === 128);
});

it('sends a document as a file part over chat completions', function (): void {
    Http::fake(['gateway.test/v1/chat/completions' => Http::response(Messages::chat('PELICAN'))]);

    (new EchoAgent)->prompt('Read it.', [Document::fromString('%PDF-1.4 bytes', 'application/pdf')], provider: 'cliproxy', model: 'm');

    Http::assertSent(function (Request $request): bool {
        $parts = collect($request['messages'])->where('role', 'user')->last()['content'];
        $file = collect($parts)->firstWhere('type', 'file');

        return is_array($file) && str_starts_with($file['file']['file_data'], 'data:application/pdf;base64,');
    });
});

it('treats a gateway 500 as a failover, not an answer', function (string $protocol, string $endpoint, int $status, string $exception): void {
    if ($protocol === 'messages') {
        useMessagesProtocol();
    }

    Http::fake(["gateway.test/v1/{$endpoint}" => Http::response(['error' => ['message' => 'upstream']], $status)]);

    expect(fn () => (new EchoAgent)->prompt('Hi', provider: 'cliproxy', model: 'm'))->toThrow($exception);
})->with([
    'chat, pool exhausted' => ['chat-completions', 'chat/completions', 500, ProviderOverloadedException::class],
    'chat, cooling down' => ['chat-completions', 'chat/completions', 429, RateLimitedException::class],
    'messages, pool exhausted' => ['messages', 'messages', 500, ProviderOverloadedException::class],
    'messages, bad gateway' => ['messages', 'messages', 502, ProviderOverloadedException::class],
]);

it('falls over to the next provider in a route when the gateway fails', function (): void {
    Http::fake([
        'gateway.test/v1/chat/completions' => Http::response(['error' => ['message' => 'down']], 500),
        'backup.test/v1/chat/completions' => Http::response(Messages::chat('from backup', 'backup-model')),
    ]);

    $response = (new EchoAgent)->prompt('Hi', provider: ['cliproxy' => 'my-alias', 'backup' => 'backup-model']);

    expect($response->text)->toBe('from backup');
});

it('moves a cache breakpoint onto the last message when a messages agent asks', function (): void {
    useMessagesProtocol();
    Http::fake(['gateway.test/v1/messages' => Http::response(Messages::messages())]);

    (new EchoAgent([MessagesGateway::CACHE_TRAILING_MESSAGE => true]))->prompt('Hi', provider: 'cliproxy', model: 'm');

    Http::assertSent(function (Request $request): bool {
        $body = $request->data();
        $last = end($body['messages']);
        $block = is_array($last['content']) ? end($last['content']) : null;

        return ! array_key_exists(MessagesGateway::CACHE_TRAILING_MESSAGE, $body)
            && is_array($block)
            && ($block['cache_control'] ?? null) === ['type' => 'ephemeral'];
    });
});

it('never sends the breakpoint option when the agent does not ask', function (): void {
    useMessagesProtocol();
    Http::fake(['gateway.test/v1/messages' => Http::response(Messages::messages())]);

    (new EchoAgent)->prompt('Hi', provider: 'cliproxy', model: 'm');

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'cache_control')
        && ! str_contains($request->body(), MessagesGateway::CACHE_TRAILING_MESSAGE));
});

it('generates images over chat completions with the image modality', function (): void {
    $png = base64_encode('png-bytes');

    Http::fake(['gateway.test/v1/chat/completions' => Http::response([
        'model' => 'my-art',
        'choices' => [['message' => ['role' => 'assistant', 'images' => [['image_url' => ['url' => "data:image/png;base64,{$png}"]]]]]],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 1],
    ])]);

    $response = Ai::imageProvider('cliproxy')->image('An apple', model: 'my-art', size: '1:1', quality: 'low');

    expect($response->images)->toHaveCount(1);

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer client-key']
        && $request['modalities'] === ['image']
        && $request['image_config'] === ['aspect_ratio' => '1:1', 'image_size' => '1K']);
});

it('asks for a model when none is configured or named', function (): void {
    expect(fn () => Ai::textProvider('cliproxy')->defaultTextModel())
        ->toThrow(InvalidArgumentException::class, 'models.text.default');
});

it('uses the default models an entry declares', function (): void {
    config(['ai.providers.cliproxy' => ['driver' => 'cliproxy', 'models' => ['text' => ['default' => 'alias-default'], 'image' => ['default' => 'alias-art']]]]);
    forgetProviders();

    expect(Ai::textProvider('cliproxy')->defaultTextModel())->toBe('alias-default')
        ->and(Ai::textProvider('cliproxy')->cheapestTextModel())->toBe('alias-default')
        ->and(Ai::imageProvider('cliproxy')->defaultImageModel())->toBe('alias-art');
});
