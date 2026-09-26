<?php

/**
 * The package against a real CLIProxyAPI, with a stand-in upstream behind it.
 *
 * Runs only when CLIPROXY_LIVE_URL names a gateway (with CLIPROXY_LIVE_KEY and
 * CLIPROXY_LIVE_MANAGEMENT_KEY). The suite starts the stand-in upstream itself and registers it
 * in the gateway as an `openai-compatibility` provider named `laravel-cliproxy-live`, reachable
 * from the gateway at CLIPROXY_LIVE_UPSTREAM (default http://host.docker.internal:8399); every
 * other upstream the gateway holds is put back exactly as it was afterwards.
 */

use Hojabbr\CliProxy\Commands\Probe\ProbeFiles;
use Hojabbr\CliProxy\Facades\CliProxy;
use Hojabbr\CliProxy\Tests\Fixtures\EchoAgent;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Ai;
use Laravel\Ai\AiManager;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Streaming\Events\TextDelta;

const LIVE_UPSTREAM = 'laravel-cliproxy-live';

beforeEach(function (): void {
    if (! env('CLIPROXY_LIVE_URL')) {
        $this->markTestSkipped('Set CLIPROXY_LIVE_URL to run the live suite against a gateway.');
    }

    $port = (int) env('CLIPROXY_LIVE_UPSTREAM_PORT', 8399);
    $this->upstream = Process::path(__DIR__.'/Upstream')->start("php -S 0.0.0.0:{$port} server.php");
    usleep(300_000);

    $management = CliProxy::management();
    $this->previous = (array) $management->request()->get('openai-compatibility')->throw()->json('openai-compatibility', []);

    $others = array_values(array_filter($this->previous, fn (array $entry): bool => ($entry['name'] ?? null) !== LIVE_UPSTREAM));
    $management->request()->put('openai-compatibility', [...$others, [
        'name' => LIVE_UPSTREAM,
        'base-url' => rtrim((string) env('CLIPROXY_LIVE_UPSTREAM', "http://host.docker.internal:{$port}"), '/').'/v1',
        'api-key-entries' => [['api-key' => 'live-upstream-key']],
        'models' => [
            ['name' => 'upstream-text', 'alias' => 'live-text'],
            ['name' => 'upstream-art', 'alias' => 'live-art', 'image' => true],
        ],
    ]])->throw();

    CliProxy::readiness()->forget();

    for ($attempt = 0; $attempt < 20 && ! CliProxy::serves('live-text'); $attempt++) {
        usleep(250_000);
        CliProxy::readiness()->forget();
    }
});

afterEach(function (): void {
    if (! env('CLIPROXY_LIVE_URL')) {
        return;
    }

    $restore = array_values(array_filter($this->previous, fn (array $entry): bool => ($entry['name'] ?? null) !== LIVE_UPSTREAM));
    CliProxy::management()->request()->put('openai-compatibility', $restore === [] ? [] : $restore);
    $this->upstream?->stop();
});

function liveProtocol(string $protocol): void
{
    config(['ai.providers.cliproxy' => ['driver' => 'cliproxy', 'protocol' => $protocol]]);
    app()->forgetInstance(AiManager::class);
    Ai::clearResolvedInstances();
}

it('sees the upstream through the gateway', function (): void {
    expect(CliProxy::isUp())->toBeTrue()
        ->and(CliProxy::serves('live-text'))->toBeTrue()
        ->and(CliProxy::management()->apiKeys())->toContain((string) env('CLIPROXY_LIVE_KEY'));
});

it('answers text, streams, tools and structured output in both protocols', function (string $protocol, array $gaps): void {
    liveProtocol($protocol);

    expect((new EchoAgent)->prompt('Say pong.', provider: 'cliproxy', model: 'live-text')->text)->toContain('pong');

    $streamed = '';
    foreach ((new EchoAgent)->stream('Say pong.', provider: 'cliproxy', model: 'live-text') as $event) {
        $streamed .= $event instanceof TextDelta ? $event->delta : '';
    }
    expect($streamed)->toContain('pong');

    $exit = Artisan::call('cliproxy:probe', ['model' => 'live-text', '--image-model' => 'live-art', '--json' => true]);
    $checks = collect(json_decode(Artisan::output(), true)['checks'])->keyBy('check');

    $expected = array_merge([
        'listed' => true,
        'text' => true,
        'stream' => true,
        'tools' => true,
        'schema' => true,
        'image input' => true,
        'pdf input' => true,
        'image generation' => true,
    ], array_fill_keys($gaps, false));

    expect($checks->map(fn (array $check): bool => $check['ok'])->all())->toMatchArray($expected)
        ->and($exit)->toBe($gaps === [] ? 0 : 1);
})->with([
    'chat-completions' => ['chat-completions', []],
    // CLIProxyAPI v7.3.18 translates a messages request for an openai-compatibility upstream keeping
    // only text and image blocks and output_config.effort (internal/translator/openai/claude): a
    // document block and output_config.format are dropped without an error. Pinned so the scheduled
    // run against the newest gateway sees the day this changes.
    'messages' => ['messages', ['schema', 'pdf input']],
]);

it('carries attachments through to the upstream', function (): void {
    liveProtocol('chat-completions');

    expect((new EchoAgent)->prompt('Colour?', [Image::fromBase64(base64_encode(ProbeFiles::redSquare()), 'image/png')], provider: 'cliproxy', model: 'live-text')->text)->toContain('red')
        ->and((new EchoAgent)->prompt('Word?', [Document::fromString(ProbeFiles::pdf('PELICAN'), 'application/pdf')], provider: 'cliproxy', model: 'live-text')->text)->toContain('PELICAN');
});

it('falls over to the next provider when the gateway cannot serve the model', function (): void {
    config(['ai.providers.backup' => ['driver' => 'cliproxy', 'url' => rtrim((string) env('CLIPROXY_LIVE_URL'), '/').'/v1', 'key' => env('CLIPROXY_LIVE_KEY')]]);

    $route = CliProxy::routeTo('no-such-model', ['backup' => 'live-text']);

    expect($route->providers())->toBe(['backup' => 'live-text'])
        ->and((new EchoAgent)->prompt('Say pong.', provider: $route->providers())->text)->toContain('pong');
});

it('runs a batch through the gateway in both protocols', function (string $protocol, array $params): void {
    config(['cliproxy.batches.protocol' => $protocol]);

    $status = CliProxy::batches()->create([
        ['custom_id' => 'a', 'params' => $params],
        ['custom_id' => 'b', 'params' => $params],
    ]);

    expect($status->hasEnded())->toBeTrue()
        ->and($status->requestCounts['succeeded'])->toBe(2)
        ->and(CliProxy::batches()->results($status->id)->map->text()->all())->each->toContain('pong');
})->with([
    'chat-completions' => ['chat-completions', ['model' => 'live-text', 'messages' => [['role' => 'user', 'content' => 'Say pong.']]]],
    'messages' => ['messages', ['model' => 'live-text', 'max_tokens' => 32, 'messages' => [['role' => 'user', 'content' => 'Say pong.']]]],
]);
