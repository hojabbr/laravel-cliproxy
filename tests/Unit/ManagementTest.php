<?php

use Hojabbr\CliProxy\Exceptions\ManagementUnavailable;
use Hojabbr\CliProxy\Facades\CliProxy;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('reads the management API with the management key', function (): void {
    Http::fake([
        'gateway.test/v0/management/api-keys' => Http::response(['api-keys' => ['one', 'two']]),
        'gateway.test/v0/management/auth-files' => Http::response(['files' => [['name' => 'codex-a.json', 'provider' => 'codex', 'status' => 'active']]]),
        'gateway.test/v0/management/quota/providers' => Http::response(['providers' => []]),
        'gateway.test/v0/management/config' => Http::response(['api-keys' => ['one'], 'debug' => false]),
    ]);

    $management = CliProxy::management();

    expect($management->apiKeys())->toBe(['one', 'two'])
        ->and($management->credentials()[0]['provider'])->toBe('codex')
        ->and($management->quotaProviders())->toBe([])
        ->and($management->config()['debug'])->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer management-key']);
});

it('replaces the accepted client keys and asks one credential for its quota', function (): void {
    Http::fake([
        'gateway.test/v0/management/api-keys' => Http::response(['status' => 'ok']),
        'gateway.test/v0/management/quota/fetch' => Http::response(['windows' => [['name' => '5h', 'used_percent' => 12]]]),
    ]);

    CliProxy::management()->setApiKeys(['tenant-a', 'tenant-b']);

    expect(CliProxy::management()->quota('auth-1')['windows'][0]['used_percent'])->toBe(12);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->data() === ['tenant-a', 'tenant-b']);
    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'quota/fetch') && $request['auth_index'] === 'auth-1');
});

it('refuses management calls without a management key', function (): void {
    config(['cliproxy.management_key' => '']);

    expect(CliProxy::management()->isConfigured())->toBeFalse()
        ->and(fn () => CliProxy::management()->apiKeys())->toThrow(ManagementUnavailable::class);
});
