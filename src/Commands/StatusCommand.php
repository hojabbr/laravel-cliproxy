<?php

namespace Hojabbr\CliProxy\Commands;

use Hojabbr\CliProxy\CliProxy;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'cliproxy:status', description: 'Show whether the gateway answers, the models it serves and, with a management key, its credentials')]
class StatusCommand extends Command
{
    protected $signature = 'cliproxy:status {--json : Print the report as JSON}';

    public function handle(CliProxy $cliproxy): int
    {
        $cliproxy->readiness()->forget();
        $state = $cliproxy->readiness()->state();

        $credentials = null;
        $credentialsError = null;

        if ($cliproxy->management()->isConfigured()) {
            try {
                $credentials = array_map(static fn (array $credential): array => [
                    'name' => self::field($credential, 'name', 'id'),
                    'provider' => self::field($credential, 'provider', 'type'),
                    'status' => self::field($credential, 'status'),
                    'disabled' => ($credential['disabled'] ?? false) === true,
                ], $cliproxy->management()->credentials());
            } catch (Throwable $failure) {
                $credentialsError = $failure->getMessage();
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'url' => $cliproxy->client()->url(),
                'up' => $state['up'],
                'error' => $state['error'],
                'models' => $state['models'],
                'credentials' => $credentials,
                'credentials_error' => $credentialsError,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $state['up'] ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('Gateway', $cliproxy->client()->url());
        $this->components->twoColumnDetail('Answers', $state['up'] ? '<fg=green>yes</>' : '<fg=red>no</> '.$state['error']);
        $this->components->twoColumnDetail('Models', (string) count($state['models']));

        foreach ($state['models'] as $model) {
            $this->components->twoColumnDetail('', $model);
        }

        if ($credentials !== null) {
            $this->newLine();
            $this->table(['Credential', 'Provider', 'Status', 'Disabled'], array_map(
                static fn (array $row): array => [$row['name'], $row['provider'], $row['status'], $row['disabled'] ? 'yes' : 'no'],
                $credentials,
            ));
        }

        if ($credentialsError !== null) {
            $this->components->warn('Management API: '.$credentialsError);
        }

        return $state['up'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The first of the named fields that holds a string, or an empty string.
     *
     * @param  array<string, mixed>  $credential
     */
    private static function field(array $credential, string ...$names): string
    {
        foreach ($names as $name) {
            if (is_string($credential[$name] ?? null)) {
                return $credential[$name];
            }
        }

        return '';
    }
}
