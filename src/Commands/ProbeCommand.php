<?php

namespace Hojabbr\CliProxy\Commands;

use Closure;
use Hojabbr\CliProxy\CliProxy;
use Hojabbr\CliProxy\Commands\Probe\ProbeAgent;
use Hojabbr\CliProxy\Commands\Probe\ProbeCacheAgent;
use Hojabbr\CliProxy\Commands\Probe\ProbeFiles;
use Hojabbr\CliProxy\Commands\Probe\ProbeSchemaAgent;
use Hojabbr\CliProxy\Commands\Probe\ProbeTool;
use Illuminate\Console\Command;
use Laravel\Ai\Ai;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * One real call per feature through the gateway, so an operator knows what a model behind it can
 * actually do before pointing work at it. Every check goes through laravel/ai's provider for the
 * gateway, in the protocol its entry names, which is the path an application uses. Each check
 * spends a few tokens upstream; `--long` and `--image-model` add the two expensive ones.
 *
 * Caching is advisory: whether an upstream reports cache reads is the upstream's business, and a
 * prefix under its minimum caches nothing without an error.
 */
#[AsCommand(name: 'cliproxy:probe', description: 'Make one real call per feature through the gateway and report what works')]
class ProbeCommand extends Command
{
    protected $signature = 'cliproxy:probe
        {model : The gateway model (or alias) to probe}
        {--provider= : The laravel/ai provider name, when not the package default}
        {--image-model= : Also generate one image with this model}
        {--long : Also ask for a long answer (about 16k output tokens)}
        {--json : Print the report as JSON}';

    /** @var list<array{check: string, ok: bool, advisory: bool, ms: int, detail: string}> */
    private array $results = [];

    public function handle(CliProxy $cliproxy): int
    {
        $model = $this->text($this->argument('model')) ?? $cliproxy->provider();
        $provider = $this->text($this->option('provider')) ?? $cliproxy->provider();
        $word = fn (string $rules = 'Answer with exactly one word.'): ProbeAgent => new ProbeAgent($rules, 64);

        $cliproxy->readiness()->forget();

        $this->check('listed', fn (): array => [$cliproxy->serves($model), $cliproxy->serves($model) ? 'listed by /v1/models' : 'not in /v1/models']);

        $this->check('text', fn (): array => $this->expect($word()->prompt('Say pong.', provider: $provider, model: $model)->text, 'pong'));

        $this->check('stream', function () use ($word, $provider, $model): array {
            $text = '';

            foreach ($word()->stream('Say pong.', provider: $provider, model: $model) as $event) {
                $text .= $event instanceof TextDelta ? $event->delta : '';
            }

            return $this->expect($text, 'pong');
        });

        $this->check('tools', function () use ($provider, $model): array {
            $tool = new ProbeTool;
            $text = (new ProbeAgent('Use the tool when asked for the secret number, then state it.', 256, [$tool]))
                ->prompt('What is the secret number?', provider: $provider, model: $model)->text;

            return [str_contains($text, (string) ProbeTool::NUMBER) && $tool->calls > 0, $this->clip($text)];
        });

        $this->check('schema', function () use ($provider, $model): array {
            $response = (new ProbeSchemaAgent)->prompt('What is six times seven?', provider: $provider, model: $model);

            if (! $response instanceof StructuredAgentResponse) {
                return [false, 'the answer was not structured: '.$this->clip($response->text)];
            }

            return [($response['answer'] ?? null) === 42, (string) json_encode($response->toArray())];
        });

        $this->check('image input', fn (): array => $this->expect($word()->prompt(
            'What colour is this square?',
            [Image::fromBase64(base64_encode(ProbeFiles::redSquare()), 'image/png')],
            provider: $provider,
            model: $model,
        )->text, 'red'));

        $this->check('pdf input', fn (): array => $this->expect($word('Copy the one word printed in the document, exactly.')->prompt(
            'What word is printed?',
            [Document::fromString(ProbeFiles::pdf(ProbeFiles::PDF_WORD), 'application/pdf')],
            provider: $provider,
            model: $model,
        )->text, strtolower(ProbeFiles::PDF_WORD)));

        $this->check('cache', function () use ($provider, $model): array {
            (new ProbeCacheAgent)->prompt('Reply with OK.', provider: $provider, model: $model);
            $usage = (new ProbeCacheAgent)->prompt('Reply with OK.', provider: $provider, model: $model)->usage;

            return [(int) $usage->cacheReadInputTokens > 0, "second call: input={$usage->inputTokens}, cache_read=".(int) $usage->cacheReadInputTokens];
        }, advisory: true);

        if ($this->option('long')) {
            $this->check('long answer', function () use ($provider, $model): array {
                $response = (new ProbeAgent('Follow the instruction exactly.', 16_000))
                    ->prompt('Write the numbers from 1 to 3000 in English words, one per line, with no other text.', provider: $provider, model: $model, timeout: 600);

                return [$response->usage->outputTokens > 4_000, "output_tokens={$response->usage->outputTokens}"];
            });
        }

        if (($image = $this->text($this->option('image-model'))) !== null) {
            $this->check('image generation', function () use ($provider, $image): array {
                $response = Ai::imageProvider($provider)->image('A single red apple on a plain white background.', model: $image, size: '1:1', timeout: 180);

                return [count($response->images) > 0, count($response->images).' image(s)'];
            });
        }

        return $this->report();
    }

    /**
     * @param  Closure(): array{0: bool, 1: string}  $probe
     */
    private function check(string $name, Closure $probe, bool $advisory = false): void
    {
        $started = hrtime(true);

        try {
            [$ok, $detail] = $probe();
        } catch (Throwable $failure) {
            [$ok, $detail] = [false, class_basename($failure).': '.$this->clip($failure->getMessage())];
        }

        $this->results[] = [
            'check' => $name,
            'ok' => $ok,
            'advisory' => $advisory,
            'ms' => intdiv(hrtime(true) - $started, 1_000_000),
            'detail' => $detail,
        ];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function expect(string $text, string $needle): array
    {
        return [str_contains(strtolower($text), $needle), $this->clip($text)];
    }

    private function report(): int
    {
        $failed = array_filter($this->results, static fn (array $result): bool => ! $result['ok'] && ! $result['advisory']);

        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => $failed === [], 'checks' => $this->results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(['Check', 'Result', 'ms', 'Detail'], array_map(static fn (array $result): array => [
                $result['check'],
                $result['ok'] ? '<fg=green>pass</>' : ($result['advisory'] ? '<fg=yellow>no</>' : '<fg=red>fail</>'),
                $result['ms'],
                $result['detail'],
            ], $this->results));
        }

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function clip(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 117).'...' : $text;
    }
}
