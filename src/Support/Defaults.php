<?php

namespace Grafite\Blacksmith\Support;

/**
 * The defaults in config/blacksmith.php, under "defaults".
 */
class Defaults
{
    public static function get(string $key, mixed $fallback = null): mixed
    {
        return config("blacksmith.defaults.{$key}", $fallback);
    }

    public static function section(string $section): array
    {
        return (array) static::get($section, []);
    }

    public static function user(): string
    {
        return (string) static::get('user', 'forge');
    }
}
