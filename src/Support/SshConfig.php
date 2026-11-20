<?php

namespace Grafite\Blacksmith\Support;

use RuntimeException;

/**
 * Keeps a Host entry per instance in your SSH config, so `ssh {project}-{instance}` reaches it as the Forge user.
 *
 * Each entry sits between its own BEGIN / END markers, nothing outside them is changed.
 * New entries are added at the end, so a `Host *` block above them still applies.
 */
class SshConfig
{
    public function __construct(
        protected ?string $path,
        protected bool $enabled = true,
        protected ?string $project = null,
        protected string $user = 'forge',
        protected int $port = 22,
        protected ?string $key = null,
    ) {}

    public function enabled(): bool
    {
        return $this->enabled && ! empty($this->path);
    }

    public function path(): ?string
    {
        return $this->path ? static::expand($this->path) : null;
    }

    /**
     * The Host name for an instance, prefixed with the project so instances from different projects don't clash.
     */
    public function alias(string $instance): string
    {
        $project = trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $this->project), '-');

        return $project !== '' ? "{$project}-{$instance}" : $instance;
    }

    /**
     * Add or update the instance's entry.
     */
    public function put(string $instance, string $ip): void
    {
        if (! $this->enabled()) {
            return;
        }

        $contents = $this->read();
        $block = $this->block($instance, $ip);
        $pattern = $this->pattern($this->alias($instance));

        if (preg_match($pattern, $contents)) {
            // Updated where it is, so its place among your other entries is kept.
            $updated = preg_replace_callback($pattern, fn () => $block, $contents, 1);
        } else {
            $updated = rtrim($contents) === '' ? $block : rtrim($contents).PHP_EOL.PHP_EOL.$block;
        }

        if ($updated !== $contents) {
            $this->write($updated);
        }
    }

    public function forget(string $instance): void
    {
        if (! $this->enabled() || ! is_file($this->path())) {
            return;
        }

        $contents = $this->read();

        // The blank line that separated the entry goes with it.
        $without = preg_replace('/(?:(?<=\n)\r?\n)?'.substr($this->pattern($this->alias($instance)), 1), '', $contents, 1);

        if ($without !== null && $without !== $contents) {
            $this->write($without);
        }
    }

    public function has(string $instance): bool
    {
        return $this->enabled() && is_file($this->path())
            && str_contains($this->read(), static::begin($this->alias($instance)));
    }

    public function block(string $instance, string $ip): string
    {
        $alias = $this->alias($instance);

        $lines = array_filter([
            static::begin($alias),
            "Host {$alias}",
            "    HostName {$ip}",
            "    User {$this->user}",
            $this->port !== 22 ? "    Port {$this->port}" : null,
            $this->key ? "    IdentityFile {$this->key}" : null,
            static::end($alias),
        ]);

        return implode(PHP_EOL, $lines).PHP_EOL;
    }

    /**
     * Matches the entry from its BEGIN line through its END line and line break.
     */
    protected function pattern(string $alias): string
    {
        return '/^'.preg_quote(static::begin($alias), '/').'$.*?^'.preg_quote(static::end($alias), '/').'$\R?/ms';
    }

    protected function read(): string
    {
        $path = $this->path();

        if (! is_file($path)) {
            return '';
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Unable to read {$path}.");
        }

        return $contents;
    }

    /**
     * Write through a temporary file, so a failed write can't leave a half written config.
     */
    protected function write(string $contents): void
    {
        $path = $this->path();
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create {$directory}.");
        }

        $mode = is_file($path) ? (fileperms($path) & 0777) : 0600;
        $temporary = $directory.'/.'.basename($path).'.blacksmith-'.bin2hex(random_bytes(4));

        if (@file_put_contents($temporary, $contents) === false) {
            throw new RuntimeException("Unable to write {$path}.");
        }

        @chmod($temporary, $mode);

        if (! @rename($temporary, $path)) {
            @unlink($temporary);

            throw new RuntimeException("Unable to write {$path}.");
        }
    }

    protected static function begin(string $alias): string
    {
        return "# BEGIN blacksmith {$alias}";
    }

    protected static function end(string $alias): string
    {
        return "# END blacksmith {$alias}";
    }

    protected static function expand(string $path): string
    {
        if ($path === '~' || str_starts_with($path, '~/')) {
            $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');

            return rtrim($home, '/').substr($path, 1);
        }

        return $path;
    }
}
