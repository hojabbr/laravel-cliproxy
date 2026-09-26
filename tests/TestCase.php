<?php

namespace Hojabbr\CliProxy\Tests;

use Hojabbr\CliProxy\CliProxyServiceProvider;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * A minimal Laravel application with laravel/ai and this package registered.
 *
 * Testbench is here so the suite runs on its own, in this repository's CI, rather than only
 * inside an application that happens to consume the package.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, CliProxyServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cliproxy.url', env('CLIPROXY_LIVE_URL', 'http://gateway.test'));
        $app['config']->set('cliproxy.key', env('CLIPROXY_LIVE_KEY', 'client-key'));
        $app['config']->set('cliproxy.management_key', env('CLIPROXY_LIVE_MANAGEMENT_KEY', 'management-key'));
        // A fallback of the application's choosing: any laravel/ai provider will do.
        $app['config']->set('ai.providers.backup', ['driver' => 'openai-compatible', 'key' => 'backup-key', 'url' => 'https://backup.test/v1']);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
