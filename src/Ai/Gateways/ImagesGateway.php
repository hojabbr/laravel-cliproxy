<?php

namespace Hojabbr\CliProxy\Ai\Gateways;

use Laravel\Ai\Gateway\OpenRouter\OpenRouterGateway;

/**
 * Images through the gateway, as OpenRouter-style `chat/completions` with `modalities: ['image']`.
 *
 * CLIProxyAPI forwards chat completions to any `openai-compatibility` upstream, so an image model
 * behind OpenRouter (or any upstream speaking that convention) answers through the gateway exactly
 * as it would directly. A 500 fails over, for the same reason as {@see ChatCompletionsGateway}.
 */
class ImagesGateway extends OpenRouterGateway
{
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
