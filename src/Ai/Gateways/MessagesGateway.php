<?php

namespace Hojabbr\CliProxy\Ai\Gateways;

use Hojabbr\CliProxy\Ai\Concerns\CachesTrailingMessage;
use Laravel\Ai\Gateway\Anthropic\AnthropicGateway;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Providers\Provider;

/**
 * Text through the gateway's `/v1/messages` endpoint (the `messages` protocol).
 *
 * The gateway translates the request for whichever upstream serves the model. Choose this
 * protocol when you want its request features on the wire: explicit prompt-cache breakpoints,
 * document blocks, thinking. Two things differ from laravel/ai's own gateway for this format:
 *
 * - A 500 is a failover, not an answer. The gateway reports an upstream it could not reach, or
 *   a pool whose every credential failed, as a 500, which is when the next provider in a route
 *   should take the call.
 * - The moving cache breakpoint of {@see CachesTrailingMessage} is available.
 */
class MessagesGateway extends AnthropicGateway
{
    use CachesTrailingMessage;

    /**
     * {@inheritdoc}
     *
     * @param  array<array-key, mixed>  $messages
     * @param  array<array-key, mixed>  $tools
     * @param  array<array-key, mixed>|null  $schema
     * @return array<array-key, mixed>
     */
    protected function buildTextRequestBody(
        Provider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
    ): array {
        return $this->applyTrailingMessageCache(parent::buildTextRequestBody(
            $provider, $model, $instructions, $messages, $tools, $schema, $options,
        ));
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
