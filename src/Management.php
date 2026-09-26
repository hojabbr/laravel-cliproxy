<?php

namespace Hojabbr\CliProxy;

use Hojabbr\CliProxy\Exceptions\ManagementUnavailable;
use Hojabbr\CliProxy\Support\Options;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * The gateway's management API (`/v0/management`), read-mostly.
 *
 * Answers are returned as the gateway sends them, as arrays: this API moves with CLIProxyAPI's
 * releases, and a typed wrapper would turn every new field into a package release. The methods
 * name the calls an application needs to watch a gateway, not the whole surface; `request()`
 * reaches the rest.
 */
class Management
{
    public function __construct(
        protected Factory $http,
        protected Client $client,
    ) {}

    public function isConfigured(): bool
    {
        return $this->key() !== null;
    }

    public function request(?int $timeout = null): PendingRequest
    {
        $key = $this->key() ?? throw ManagementUnavailable::missingKey();

        return $this->http->baseUrl($this->client->url().'/v0/management')
            ->withToken($key)
            ->acceptJson()
            ->asJson()
            ->connectTimeout(Options::int('cliproxy.connect_timeout', 5))
            ->timeout($timeout ?? 15);
    }

    /**
     * The running config, as the gateway reports it.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return self::object($this->request()->get('config')->throw());
    }

    /**
     * The client keys the gateway accepts.
     *
     * @return list<string>
     */
    public function apiKeys(): array
    {
        $keys = $this->request()->get('api-keys')->throw()->json('api-keys');

        return array_values(array_filter(is_array($keys) ? $keys : [], is_string(...)));
    }

    /**
     * Replace the client keys the gateway accepts.
     *
     * @param  list<string>  $keys
     */
    public function setApiKeys(array $keys): void
    {
        $this->request()->put('api-keys', $keys)->throw();
    }

    /**
     * The upstream credentials the gateway holds (OAuth logins and imported keys), with their
     * status as the gateway last saw it.
     *
     * @return list<array<string, mixed>>
     */
    public function credentials(): array
    {
        return self::objects($this->request()->get('auth-files')->throw()->json('files'));
    }

    /**
     * The quota providers the gateway can ask about a credential.
     *
     * @return list<array<string, mixed>>
     */
    public function quotaProviders(): array
    {
        return self::objects($this->request()->get('quota/providers')->throw()->json('providers'));
    }

    /**
     * One credential's quota windows, fetched from its vendor now. The shape is the quota
     * provider's own.
     *
     * @return array<string, mixed>
     */
    public function quota(string $authIndex): array
    {
        return self::object($this->request(30)->post('quota/fetch', ['auth_index' => $authIndex])->throw());
    }

    protected function key(): ?string
    {
        return Options::nullableString('cliproxy.management_key');
    }

    /**
     * @return array<string, mixed>
     */
    private static function object(Response $response): array
    {
        $data = $response->json();

        return is_array($data) ? self::stringKeyed($data) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function objects(mixed $items): array
    {
        $objects = [];

        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item)) {
                $objects[] = self::stringKeyed($item);
            }
        }

        return $objects;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $data): array
    {
        return array_filter($data, is_string(...), ARRAY_FILTER_USE_KEY);
    }
}
