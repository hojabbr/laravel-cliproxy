<?php

namespace Hojabbr\CliProxy\Ai\Concerns;

/**
 * A prompt-cache breakpoint that moves with the conversation, for the `messages` protocol.
 *
 * An agent's provider options can cache its static prefix (tools and instructions), but every
 * step of a tool loop also re-sends the whole conversation so far. Marking the last content
 * block of the last message as a breakpoint caches that too: each step's breakpoint is a prefix
 * of the next request, and the API looks back about twenty blocks for a match.
 *
 * Opt in by returning `[CACHE_TRAILING_MESSAGE => true]` from the agent's `providerOptions()`.
 * The key is always removed before the body is sent, and it is deliberately not a plausible
 * API field, so a gateway that failed to strip it would answer with an obvious 400.
 *
 * Any subclass of laravel/ai's gateway for this wire format (`AnthropicGateway`) can use this trait; the package's own
 * `MessagesGateway` does.
 */
trait CachesTrailingMessage
{
    public const string CACHE_TRAILING_MESSAGE = 'laravel_cliproxy_cache_trailing_message';

    /**
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     */
    protected function applyTrailingMessageCache(array $body): array
    {
        $asked = ($body[self::CACHE_TRAILING_MESSAGE] ?? null) === true;

        unset($body[self::CACHE_TRAILING_MESSAGE]);

        return $asked ? $this->cacheTrailingMessage($body) : $body;
    }

    /**
     * Mark the last content block of the last message. Every shape this has no business
     * rewriting (a bare string, an empty transcript, a block that already carries a breakpoint)
     * leaves the body as it was: a wrong `cache_control` is a 400 on a call someone waits for.
     *
     * @param  array<array-key, mixed>  $body
     * @return array<array-key, mixed>
     */
    protected function cacheTrailingMessage(array $body): array
    {
        $messages = $body['messages'] ?? null;

        if (! is_array($messages) || $messages === []) {
            return $body;
        }

        $lastMessage = array_key_last($messages);
        $message = $messages[$lastMessage];
        $content = is_array($message) ? ($message['content'] ?? null) : null;

        if (! is_array($message) || ! is_array($content) || $content === []) {
            return $body;
        }

        $lastBlock = array_key_last($content);
        $block = $content[$lastBlock];

        if (! is_array($block) || isset($block['cache_control'])) {
            return $body;
        }

        $block['cache_control'] = ['type' => 'ephemeral'];
        $content[$lastBlock] = $block;
        $message['content'] = $content;
        $messages[$lastMessage] = $message;
        $body['messages'] = $messages;

        return $body;
    }
}
