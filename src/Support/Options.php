<?php

namespace Hojabbr\CliProxy\Support;

/**
 * The package's config, read with the types it means.
 *
 * Values often arrive from the environment as strings ("600", "true"), so a number is accepted
 * from a numeric string and anything unusable falls back to the default rather than throwing
 * mid-request. Read on every call, never cached, so an application's own overlay of `cliproxy.*`
 * is always what the package sees.
 *
 * @internal
 */
final class Options
{
    public static function string(string $key, string $default = ''): string
    {
        $value = config($key);

        return is_string($value) || is_int($value) ? (string) $value : $default;
    }

    public static function nullableString(string $key): ?string
    {
        $value = trim(self::string($key));

        return $value === '' ? null : $value;
    }

    public static function int(string $key, int $default): int
    {
        $value = config($key);

        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = config($key);

        return is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default);
    }

    /**
     * @return list<int>
     */
    public static function ints(string $key, int $default): array
    {
        $value = config($key);
        $ints = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_int($item) || (is_string($item) && is_numeric($item))) {
                $ints[] = (int) $item;
            }
        }

        return $ints === [] ? [$default] : $ints;
    }

    /**
     * A map of strings to strings, dropping whatever is not.
     *
     * @return array<string, string>
     */
    public static function stringMap(mixed $value): array
    {
        $map = [];

        foreach (is_array($value) ? $value : [] as $key => $item) {
            if (is_string($key) && is_string($item) && $item !== '') {
                $map[$key] = $item;
            }
        }

        return $map;
    }

    /**
     * The gateway's laravel/ai provider name.
     */
    public static function provider(): string
    {
        return self::string('cliproxy.provider', 'cliproxy');
    }
}
