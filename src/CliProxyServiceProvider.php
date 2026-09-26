<?php

namespace Hojabbr\CliProxy;

use Hojabbr\CliProxy\Ai\CliProxyProvider;
use Hojabbr\CliProxy\Commands\ProbeCommand;
use Hojabbr\CliProxy\Commands\StatusCommand;
use Hojabbr\CliProxy\Support\Options;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\AiManager;

class CliProxyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/cliproxy.php', 'cliproxy');

        $this->app->singleton(CliProxy::class, static fn (Application $app): CliProxy => new CliProxy($app));
    }

    public function boot(): void
    {
        $this->registerAiProvider();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/cliproxy.php' => config_path('cliproxy.php'),
            ], 'cliproxy-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'cliproxy-migrations');

            $this->commands([StatusCommand::class, ProbeCommand::class]);

            AboutCommand::add('CLIProxyAPI', fn (): array => [
                'Gateway' => $this->app->make(Client::class)->url(),
                'Provider' => Options::provider(),
            ]);
        }
    }

    /**
     * Teach laravel/ai the `cliproxy` driver, and declare the provider entry when the
     * application has not.
     *
     * The entry's URL and key are filled when the provider is first resolved, not here, so an
     * application that overlays `cliproxy.url` / `cliproxy.key` during its own boot is honoured.
     * Registered in boot because laravel/ai caches a provider on first resolution, and a creator
     * added after that would be ignored in silence.
     */
    protected function registerAiProvider(): void
    {
        $name = Options::provider();

        if (config("ai.providers.{$name}") === null) {
            config(["ai.providers.{$name}" => ['driver' => 'cliproxy']]);
        }

        $this->callAfterResolving(AiManager::class, function (AiManager $manager): void {
            $manager->extend('cliproxy', fn (Application $app, array $config): CliProxyProvider => CliProxyProvider::make($app, $config));
        });
    }
}
