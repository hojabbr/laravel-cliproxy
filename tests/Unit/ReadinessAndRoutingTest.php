<?php

use Hojabbr\CliProxy\Exceptions\RouteUnavailable;
use Hojabbr\CliProxy\Facades\CliProxy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('lists the models the gateway serves and remembers the answer', function (): void {
    Http::fake(['gateway.test/v1/models' => Http::response(['object' => 'list', 'data' => [['id' => 'alias-a'], ['id' => 'alias-b']]])]);

    expect(CliProxy::models())->toBe(['alias-a', 'alias-b'])
        ->and(CliProxy::serves('alias-b'))->toBeTrue()
        ->and(CliProxy::serves('alias-c'))->toBeFalse()
        ->and(CliProxy::isUp())->toBeTrue();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer client-key']);
});

it('reads a gateway that does not answer as down, and asks again only after forgetting', function (): void {
    Http::fake(['gateway.test/v1/models' => Http::sequence()
        ->push(['error' => 'Missing API key'], 401)
        ->push(['data' => [['id' => 'alias-a']]])]);

    expect(CliProxy::isUp())->toBeFalse()
        ->and(CliProxy::readiness()->state()['error'])->toContain('401')
        ->and(CliProxy::serves('alias-a'))->toBeFalse();

    CliProxy::readiness()->forget();

    expect(CliProxy::serves('alias-a'))->toBeTrue();
});

it('routes to the gateway first when it is on and serving', function (): void {
    CliProxy::fake(['alias-a']);

    $route = CliProxy::routeTo('alias-a', ['backup' => 'backup-model']);

    expect($route->usesGateway())->toBeTrue()
        ->and($route->providers())->toBe(['cliproxy' => 'alias-a', 'backup' => 'backup-model'])
        ->and($route->provider())->toBe('cliproxy')
        ->and($route->model())->toBe('alias-a');
});

it('routes to the fallback when the gateway is off, down or does not serve the model', function (bool $enabled, bool $up, array $serves): void {
    CliProxy::fake($serves, $up);

    $route = CliProxy::routeTo('alias-a', ['backup' => 'backup-model'], $enabled);

    expect($route->usesGateway())->toBeFalse()
        ->and($route->providers())->toBe(['backup' => 'backup-model']);
})->with([
    'switched off' => [false, true, ['alias-a']],
    'down' => [true, false, ['alias-a']],
    'not serving it' => [true, true, ['alias-b']],
]);

it('still sends a model the gateway does not list when there is nothing to fall back to', function (): void {
    CliProxy::fake([]);

    expect(CliProxy::routeTo('alias-a')->providers())->toBe(['cliproxy' => 'alias-a']);
});

it('refuses a switched-off route with no fallback', function (): void {
    CliProxy::fake(['alias-a']);

    expect(fn () => CliProxy::routeTo('alias-a', [], false))->toThrow(RouteUnavailable::class);
});

it('reads named routes from config', function (): void {
    CliProxy::fake(['alias-a']);
    config(['cliproxy.routes.chat' => ['enabled' => true, 'model' => 'alias-a', 'fallback' => ['backup' => 'backup-model']]]);

    expect(CliProxy::route('chat')->providers())->toBe(['cliproxy' => 'alias-a', 'backup' => 'backup-model'])
        ->and(fn () => CliProxy::route('missing'))->toThrow(RouteUnavailable::class);
});

it('never lists the gateway twice when it is also named as the fallback', function (): void {
    CliProxy::fake(['alias-a']);

    expect(CliProxy::routeTo('alias-a', ['cliproxy' => 'other', 'backup' => 'b'])->providers())
        ->toBe(['cliproxy' => 'alias-a', 'backup' => 'b']);
});
