<?php

namespace Grafite\Blacksmith\Support;

class EnvFile
{
    /**
     * Parse an env file into key => raw value pairs, comments and blank lines are ignored.
     */
    public static function parse(string $contents): array
    {
        $values = [];

        foreach (preg_split('/\R/', $contents) as $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=(.*)$/', $line, $matches)) {
                $values[$matches[1]] = trim($matches[2]);
            }
        }

        return $values;
    }

    /**
     * Apply the overrides to the base env, keeping the base file's order and comments.
     */
    public static function merge(string $base, string $overrides): string
    {
        $overrides = static::parse($overrides);

        if (empty($overrides)) {
            return $base;
        }

        $lines = $base === '' ? [] : preg_split('/\R/', $base);

        foreach ($lines as $index => $line) {
            if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=/', $line, $matches) && array_key_exists($matches[1], $overrides)) {
                $lines[$index] = $matches[1].'='.$overrides[$matches[1]];
                unset($overrides[$matches[1]]);
            }
        }

        if (! empty($overrides) && ! empty($lines) && end($lines) === '') {
            array_pop($lines);
        }

        foreach ($overrides as $key => $value) {
            $lines[] = $key.'='.$value;
        }

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * The entries in $instance that are new or different from $base, as an env file.
     */
    public static function diff(string $base, string $instance): string
    {
        $base = static::parse($base);

        $changed = collect(static::parse($instance))
            ->filter(fn ($value, $key) => ! array_key_exists($key, $base) || $base[$key] !== $value)
            ->map(fn ($value, $key) => $key.'='.$value);

        return $changed->isEmpty() ? '' : $changed->implode(PHP_EOL).PHP_EOL;
    }

    /**
     * Warnings for env settings that break when a site runs on more than one server.
     */
    public static function scaleWarnings(string $contents): array
    {
        $env = collect(static::parse($contents))->map(fn ($value) => trim($value, '"\''));
        $warnings = [];

        foreach (['SESSION_DRIVER' => 'sessions', 'CACHE_STORE' => 'cache', 'CACHE_DRIVER' => 'cache'] as $key => $label) {
            if ($env->get($key) === 'file') {
                $warnings[] = "{$key}=file stores {$label} on each node, use redis or database instead.";
            }
        }

        if ($env->get('DB_CONNECTION') === 'sqlite') {
            $warnings[] = 'DB_CONNECTION=sqlite keeps the database on each node, use a shared database.';
        } elseif (in_array($env->get('DB_HOST'), ['127.0.0.1', 'localhost'], true)) {
            $warnings[] = "DB_HOST={$env->get('DB_HOST')} points at each node's own database, use a shared database host.";
        }

        if (in_array($env->get('FILESYSTEM_DISK'), ['local', 'public'], true)) {
            $warnings[] = "FILESYSTEM_DISK={$env->get('FILESYSTEM_DISK')} stores uploads on each node, consider s3.";
        }

        return $warnings;
    }
}
