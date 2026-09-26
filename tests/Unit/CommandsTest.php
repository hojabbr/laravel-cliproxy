<?php

use Hojabbr\CliProxy\Commands\Probe\ProbeFiles;
use Hojabbr\CliProxy\Tests\Fixtures\Messages;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

it('reports a gateway that answers, with its models and credentials', function (): void {
    Http::fake([
        'gateway.test/v1/models' => Http::response(['data' => [['id' => 'alias-a']]]),
        'gateway.test/v0/management/auth-files' => Http::response(['files' => [['name' => 'codex-a.json', 'provider' => 'codex', 'status' => 'active']]]),
    ]);

    $this->artisan('cliproxy:status')
        ->expectsOutputToContain('alias-a')
        ->expectsOutputToContain('codex-a.json')
        ->assertSuccessful();
});

it('fails the status of a gateway that does not answer', function (): void {
    Http::fake(['gateway.test/*' => Http::response('nope', 503)]);

    $this->artisan('cliproxy:status', ['--json' => true])
        ->expectsOutputToContain('"up": false')
        ->assertFailed();
});

it('probes a model and fails when a required check fails', function (): void {
    Http::fake([
        'gateway.test/v1/models' => Http::response(['data' => [['id' => 'alias-a']]]),
        'gateway.test/v1/chat/completions' => Http::response(Messages::chat('pong')),
    ]);

    $exit = Artisan::call('cliproxy:probe', ['model' => 'alias-a', '--json' => true]);
    $report = json_decode(Artisan::output(), true);
    $checks = collect($report['checks'])->keyBy('check');

    expect($exit)->toBe(1)
        ->and($report['ok'])->toBeFalse()
        ->and($checks['listed']['ok'])->toBeTrue()
        ->and($checks['text']['ok'])->toBeTrue()
        ->and($checks['cache']['advisory'])->toBeTrue()
        ->and($checks->keys()->all())->toBe(['listed', 'text', 'stream', 'tools', 'schema', 'image input', 'pdf input', 'cache']);
});

it('builds probe files a reader can open', function (): void {
    $png = ProbeFiles::redSquare();
    $pdf = ProbeFiles::pdf('PELICAN');

    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1A\n")
        ->and(getimagesizefromstring($png))->toMatchArray([0 => 32, 1 => 32])
        ->and($pdf)->toStartWith('%PDF-1.4')->toContain('(PELICAN) Tj')->toEndWith("%%EOF\n")
        ->and(strlen(ProbeFiles::longPrefix()))->toBeGreaterThan(15_000);

    preg_match('/startxref\n(\d+)/', $pdf, $match);
    expect(substr($pdf, (int) $match[1], 4))->toBe('xref');
});
