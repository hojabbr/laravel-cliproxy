<?php

namespace Hojabbr\CliProxy\Tests\Fixtures;

/**
 * Gateway answers in each wire protocol's shape.
 */
final class Messages
{
    /**
     * @return array<string, mixed>
     */
    public static function chat(string $text = 'pong', string $model = 'gateway-model'): array
    {
        return [
            'id' => 'chatcmpl_'.md5($text),
            'object' => 'chat.completion',
            'model' => $model,
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $text]]],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function messages(string $text = 'pong', string $model = 'gateway-model'): array
    {
        return [
            'id' => 'msg_'.md5($text),
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => [['type' => 'text', 'text' => $text]],
            'stop_reason' => 'end_turn',
            'stop_sequence' => null,
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0],
        ];
    }
}
