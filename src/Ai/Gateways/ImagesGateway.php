<?php

namespace Hojabbr\CliProxy\Ai\Gateways;

use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Gateway\OpenRouter\OpenRouterGateway;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\ImageResponse;

/**
 * Images through the gateway, as OpenRouter-style `chat/completions` with `modalities: ['image']`.
 *
 * CLIProxyAPI forwards chat completions to any `openai-compatibility` upstream, so an image model
 * behind OpenRouter (or any upstream speaking that convention) answers through the gateway exactly
 * as it would directly. The request is built here rather than inherited: laravel/ai 1.3 moved
 * OpenRouter's images to its `images` endpoint, which the gateway does not serve. A 500 fails over,
 * for the same reason as {@see ChatCompletionsGateway}.
 */
class ImagesGateway extends OpenRouterGateway
{
    /**
     * {@inheritdoc}
     *
     * @param  array<string, mixed>  $providerOptions
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
        array $providerOptions = [],
    ): ImageResponse {
        assert($provider instanceof Provider);

        $options = $provider->defaultImageOptions($size, $quality);
        $imageConfig = array_filter([
            'aspect_ratio' => $options['aspect_ratio'] ?? null,
            'image_size' => $options['image_size'] ?? null,
        ]);
        $content = $attachments === []
            ? $prompt
            : [['type' => 'text', 'text' => $prompt], ...$this->mapAttachments(collect($attachments))];

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout ?? 120)
                ->post('chat/completions', array_merge($providerOptions, array_filter([
                    'model' => $model,
                    'messages' => [['role' => 'user', 'content' => $content]],
                    'modalities' => ['image'],
                    'image_config' => $imageConfig ?: null,
                ])))
        );

        $data = $response->fluent();

        $images = $data->collect('choices.0.message.images')
            ->map(function (mixed $image): ?GeneratedImage {
                $url = data_get($image, 'image_url.url');

                return is_string($url) && preg_match('/^data:(image\/[\w+.-]+);base64,(.+)$/', $url, $matches)
                    ? new GeneratedImage($matches[2], $matches[1])
                    : null;
            })
            ->filter()
            ->values();

        return new ImageResponse(
            $images,
            new ImageUsage($data->integer('usage.prompt_tokens'), $data->integer('usage.completion_tokens')),
            new Meta($provider->name(), $data->string('model', $model)->toString()),
        );
    }

    /**
     * {@inheritdoc}
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        return array_values(array_filter([500, ...parent::overloadedStatusCodes()], is_int(...)));
    }
}
