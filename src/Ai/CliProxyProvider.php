<?php

namespace Hojabbr\CliProxy\Ai;

use Hojabbr\CliProxy\Ai\Gateways\ChatCompletionsGateway;
use Hojabbr\CliProxy\Ai\Gateways\ImagesGateway;
use Hojabbr\CliProxy\Ai\Gateways\MessagesGateway;
use Hojabbr\CliProxy\Client;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Providers\Concerns\GeneratesImages;
use Laravel\Ai\Providers\Concerns\GeneratesText;
use Laravel\Ai\Providers\Concerns\HasImageGateway;
use Laravel\Ai\Providers\Concerns\HasTextGateway;
use Laravel\Ai\Providers\Concerns\StreamsText;
use Laravel\Ai\Providers\Provider;

/**
 * CLIProxyAPI as a laravel/ai provider: text and images.
 *
 * Text speaks the wire protocol named by the provider entry's `protocol`: `chat-completions`
 * (the default) or `messages`. Either way the gateway translates for whatever upstream serves the
 * model, so the choice is about which request features you want on the wire, not about a vendor.
 * Images use `chat/completions` with the image modality.
 *
 * It declares no files, embeddings, audio or server tools, because the gateway has no endpoint
 * for them. Model names are whatever the gateway serves (often an alias defined in its panel), so
 * there is no built-in default: set `models.text.default` (and `cheapest`, `smartest`,
 * `models.image.default`) on the provider's entry in `ai.providers`, or name the model per call.
 */
class CliProxyProvider extends Provider implements ImageProvider, TextProvider
{
    use GeneratesImages;
    use GeneratesText;
    use HasImageGateway;
    use HasTextGateway;
    use StreamsText;

    /**
     * The provider for one `ai.providers` entry. A missing `url` or `key` is filled from
     * `cliproxy.*` now, at resolution, so values an application overlays during boot are the
     * ones used.
     *
     * @param  array<array-key, mixed>  $config
     */
    public static function make(Container $app, array $config): self
    {
        $client = $app->make(Client::class);
        $events = $app->make(Dispatcher::class);

        $config['url'] ??= $client->url().'/v1';
        $config['key'] ??= $client->key() ?? '';
        $protocol = is_string($config['protocol'] ?? null) ? $config['protocol'] : 'chat-completions';

        $text = match ($protocol) {
            'chat-completions' => new ChatCompletionsGateway($events),
            'messages' => new MessagesGateway($events),
            default => throw new InvalidArgumentException("Unknown protocol [{$protocol}] for the cliproxy provider: use chat-completions or messages."),
        };

        // The images gateway is the one that implements laravel/ai's whole Gateway contract, so it is
        // the base; text is swapped in for the protocol the entry chose.
        $provider = new self(new ImagesGateway($events), $config, $events);
        $provider->useTextGateway($text);

        return $provider;
    }

    public function defaultTextModel(): string
    {
        return $this->configuredModel('text', 'default');
    }

    public function cheapestTextModel(): string
    {
        return $this->model('text', 'cheapest') ?? $this->defaultTextModel();
    }

    public function smartestTextModel(): string
    {
        return $this->model('text', 'smartest') ?? $this->defaultTextModel();
    }

    public function defaultImageModel(): string
    {
        return $this->configuredModel('image', 'default');
    }

    /**
     * The OpenRouter image convention: a size is an aspect ratio, a quality picks the resolution.
     *
     * @return array<string, string>
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        return array_filter([
            'aspect_ratio' => $size,
            'image_size' => match ($quality) {
                'low' => '1K',
                'medium' => '2K',
                'high' => '4K',
                default => null,
            },
        ]);
    }

    private function configuredModel(string $kind, string $tier): string
    {
        return $this->model($kind, $tier) ?? throw new InvalidArgumentException("Name the model for this call, or set ai.providers.{$this->name()}.models.{$kind}.{$tier}: a gateway has no default {$kind} model.");
    }

    private function model(string $kind, string $tier): ?string
    {
        $model = data_get($this->config, "models.{$kind}.{$tier}");

        return is_string($model) && $model !== '' ? $model : null;
    }
}
