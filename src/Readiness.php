<?php

namespace Hojabbr\CliProxy;

use Hojabbr\CliProxy\Support\Options;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * Whether the gateway answers, and which models it serves, remembered for a short while.
 *
 * A route asks this before every call, so the answer is cached rather than fetched: a gateway
 * that is down is asked once per `readiness.ttl`, not once per request, and an answer that is
 * up costs a cache read. The cached value is plain data, never an object, so it survives any
 * cache store and serializer.
 */
class Readiness
{
    public function __construct(
        protected Client $client,
        protected CacheFactory $cache,
    ) {}

    /**
     * @return array{up: bool, models: list<string>, error: ?string}
     */
    public function state(): array
    {
        return self::normalise($this->store()->remember($this->cacheKey(), $this->ttl(), fn (): array => $this->probe()));
    }

    public function isUp(): bool
    {
        return $this->state()['up'];
    }

    public function serves(string $model): bool
    {
        return in_array($model, $this->state()['models'], true);
    }

    /**
     * @return list<string>
     */
    public function models(): array
    {
        return $this->state()['models'];
    }

    /**
     * Drop the remembered answer, so the next question asks the gateway again.
     */
    public function forget(): void
    {
        $this->store()->forget($this->cacheKey());
    }

    /**
     * @return array{up: bool, models: list<string>, error: ?string}
     */
    protected function probe(): array
    {
        try {
            return ['up' => true, 'models' => $this->client->models(Options::int('cliproxy.readiness.timeout', 3)), 'error' => null];
        } catch (Throwable $failure) {
            return ['up' => false, 'models' => [], 'error' => $failure->getMessage()];
        }
    }

    protected function cacheKey(): string
    {
        return 'cliproxy:readiness:'.sha1($this->client->url().'|'.$this->client->key());
    }

    protected function ttl(): int
    {
        return max(1, Options::int('cliproxy.readiness.ttl', 60));
    }

    protected function store(): Cache
    {
        return $this->cache->store(Options::nullableString('cliproxy.readiness.store'));
    }

    /**
     * A cached state as the type it was written with, whatever the store handed back (an entry
     * written by another version of this package, or by nothing at all).
     *
     * @return array{up: bool, models: list<string>, error: ?string}
     */
    protected static function normalise(mixed $state): array
    {
        if (! is_array($state)) {
            return ['up' => false, 'models' => [], 'error' => 'Unreadable readiness cache entry.'];
        }

        return [
            'up' => ($state['up'] ?? false) === true,
            'models' => array_values(array_filter((array) ($state['models'] ?? []), is_string(...))),
            'error' => is_string($state['error'] ?? null) ? $state['error'] : null,
        ];
    }
}
