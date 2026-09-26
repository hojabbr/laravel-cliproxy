<?php

namespace Hojabbr\CliProxy;

use Hojabbr\CliProxy\Support\Options;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use InvalidArgumentException;

/**
 * The gateway's client-facing API, for the calls this package makes outside laravel/ai.
 *
 * Every value is read from config on each call rather than captured at construction, so an
 * application that overlays `cliproxy.*` at boot, or per request under Octane, is always talking
 * to the gateway it currently names.
 */
class Client
{
    /**
     * The endpoint each wire protocol posts to.
     */
    public const array ENDPOINTS = [
        'chat-completions' => 'v1/chat/completions',
        'messages' => 'v1/messages',
    ];

    public function __construct(protected Factory $http) {}

    /**
     * The gateway's root URL, without a trailing slash or `/v1`.
     */
    public function url(): string
    {
        return rtrim(Options::string('cliproxy.url', 'http://127.0.0.1:8317'), '/');
    }

    public function key(): ?string
    {
        return Options::nullableString('cliproxy.key');
    }

    /**
     * A request against the gateway root, carrying the client key.
     */
    public function request(?int $timeout = null): PendingRequest
    {
        return $this->http->baseUrl($this->url())
            ->when($this->key(), fn (PendingRequest $request, string $key): PendingRequest => $request->withToken($key))
            ->acceptJson()
            ->asJson()
            ->connectTimeout(Options::int('cliproxy.connect_timeout', 5))
            ->timeout($timeout ?? Options::int('cliproxy.timeout', 600));
    }

    /**
     * The model ids the gateway serves right now, as its `/v1/models` lists them.
     *
     * @return list<string>
     */
    public function models(?int $timeout = null): array
    {
        $data = $this->request($timeout)->get('v1/models')->throw()->json('data', []);

        return array_values(array_filter(array_map(
            static fn (mixed $model): ?string => is_array($model) && is_string($model['id'] ?? null) ? $model['id'] : null,
            is_array($data) ? $data : [],
        )));
    }

    /**
     * One non-streaming call in the given wire protocol, returned as it came back and never
     * thrown: the caller decides which statuses are worth another attempt.
     *
     * @param  array<string, mixed>  $body
     */
    public function send(string $protocol, array $body, ?int $timeout = null): Response
    {
        $endpoint = self::ENDPOINTS[$protocol] ?? throw new InvalidArgumentException("Unknown protocol [{$protocol}]: use chat-completions or messages.");

        return $this->request($timeout)->post($endpoint, ['stream' => false] + $body);
    }
}
