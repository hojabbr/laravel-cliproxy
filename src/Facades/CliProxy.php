<?php

namespace Hojabbr\CliProxy\Facades;

use Hojabbr\CliProxy\Readiness;
use Hojabbr\CliProxy\Testing\FakeReadiness;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Hojabbr\CliProxy\Client client()
 * @method static \Hojabbr\CliProxy\Readiness readiness()
 * @method static \Hojabbr\CliProxy\Management management()
 * @method static \Hojabbr\CliProxy\Batches\Batches batches()
 * @method static string provider()
 * @method static \Hojabbr\CliProxy\Routing\Route route(string $name)
 * @method static \Hojabbr\CliProxy\Routing\Route routeTo(string $model, array<string, string> $fallback = [], bool $enabled = true)
 * @method static bool isUp()
 * @method static bool serves(string $model)
 * @method static list<string> models()
 *
 * @see \Hojabbr\CliProxy\CliProxy
 */
class CliProxy extends Facade
{
    /**
     * Answer readiness questions from a fixed list instead of the gateway.
     *
     * @param  list<string>  $models  The models the fake gateway serves.
     */
    public static function fake(array $models = [], bool $up = true): FakeReadiness
    {
        $fake = new FakeReadiness($models, $up);

        static::getFacadeApplication()?->instance(Readiness::class, $fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return \Hojabbr\CliProxy\CliProxy::class;
    }
}
