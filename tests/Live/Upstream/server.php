<?php

/**
 * A stand-in OpenAI-compatible upstream for the live suite, run with `php -S`.
 *
 * It sits behind a real CLIProxyAPI, so every live test crosses the gateway's own translation
 * both ways. Its answers depend only on what arrived, which is what makes them assertions:
 * "red" only when an image part reached it, the PDF word only when a file part did, a tool call
 * only when tools did, a cache read only on the second identical request.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

if (is_string($log = getenv('CLIPROXY_LIVE_LOG')) && $log !== '') {
    file_put_contents($log, json_encode(['path' => $path, 'body' => $body])."\n", FILE_APPEND);
}

header('Content-Type: application/json');

if ($path === '/v1/models') {
    echo json_encode(['object' => 'list', 'data' => [['id' => 'upstream-text'], ['id' => 'upstream-art']]]);

    return;
}

if ($path !== '/v1/chat/completions') {
    http_response_code(404);
    echo json_encode(['error' => ['message' => "No route {$path}"]]);

    return;
}

$messages = $body['messages'] ?? [];
$flat = json_encode($messages);
$usage = ['prompt_tokens' => 20, 'completion_tokens' => 3, 'total_tokens' => 23];

// A repeated identical request is a cache hit, as a prefix-caching upstream would report it.
$fingerprint = sys_get_temp_dir().'/cliproxy-live-'.md5((string) json_encode([$body['model'] ?? '', $messages, $body['tools'] ?? null]));
if (file_exists($fingerprint)) {
    $usage['prompt_tokens_details'] = ['cached_tokens' => 16];
}
touch($fingerprint);

if (in_array('image', (array) ($body['modalities'] ?? []), true)) {
    $png = base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC'));
    answer(['role' => 'assistant', 'content' => null, 'images' => [['type' => 'image_url', 'image_url' => ['url' => "data:image/png;base64,{$png}"]]]], 'stop', $usage);

    return;
}

$toolResult = null;
foreach ($messages as $message) {
    if (($message['role'] ?? null) === 'tool') {
        $toolResult = is_string($message['content'] ?? null) ? $message['content'] : json_encode($message['content']);
    }
}

if ($toolResult === null && ! empty($body['tools'])) {
    $tool = $body['tools'][0]['function']['name'] ?? 'tool';
    answer(['role' => 'assistant', 'content' => null, 'tool_calls' => [[
        'id' => 'call_live_1',
        'type' => 'function',
        'function' => ['name' => $tool, 'arguments' => '{}'],
    ]]], 'tool_calls', $usage);

    return;
}

$text = match (true) {
    $toolResult !== null => "The secret number is {$toolResult}.",
    isset($body['response_format']) || str_contains($flat, 'six times seven') && str_contains((string) json_encode($body), 'json') => json_encode(['answer' => 42]),
    str_contains($flat, 'image_url') || str_contains($flat, '"type":"image') => 'red',
    str_contains($flat, 'file_data') || str_contains($flat, '"type":"file"') => 'PELICAN',
    str_contains($flat, '1 to 3000') => str_repeat('word ', 5000),
    default => 'pong',
};

if (str_contains($flat, '1 to 3000')) {
    $usage['completion_tokens'] = 5000;
}

if (($body['stream'] ?? false) === true) {
    header('Content-Type: text/event-stream');
    foreach (str_split($text, 2) as $index => $piece) {
        echo 'data: '.json_encode(['id' => 'chatcmpl-live', 'object' => 'chat.completion.chunk', 'model' => $body['model'] ?? '', 'choices' => [['index' => 0, 'delta' => $index === 0 ? ['role' => 'assistant', 'content' => $piece] : ['content' => $piece], 'finish_reason' => null]]])."\n\n";
    }
    echo 'data: '.json_encode(['id' => 'chatcmpl-live', 'object' => 'chat.completion.chunk', 'model' => $body['model'] ?? '', 'choices' => [['index' => 0, 'delta' => new stdClass, 'finish_reason' => 'stop']], 'usage' => $usage])."\n\n";
    echo "data: [DONE]\n\n";

    return;
}

answer(['role' => 'assistant', 'content' => $text], 'stop', $usage);

/**
 * @param  array<string, mixed>  $message
 * @param  array<string, mixed>  $usage
 */
function answer(array $message, string $finish, array $usage): void
{
    echo json_encode([
        'id' => 'chatcmpl-live',
        'object' => 'chat.completion',
        'created' => time(),
        'model' => $GLOBALS['body']['model'] ?? 'upstream-text',
        'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $finish]],
        'usage' => $usage,
    ]);
}
