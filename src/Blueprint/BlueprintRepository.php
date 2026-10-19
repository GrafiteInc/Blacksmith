<?php

namespace Grafite\Blacksmith\Blueprint;

use Grafite\Blacksmith\Support\EnvFile;
use RuntimeException;

/**
 * The portable description of servers and sites. Nothing in here
 * references a Forge ID, that lives in the StateRepository.
 *
 * .blacksmith/servers/{profile}.json
 * .blacksmith/sites/{domain}/site.json
 * .blacksmith/sites/{domain}/.env
 * .blacksmith/sites/{domain}/.env.{instance}
 * .blacksmith/sites/{domain}/deploy.sh
 */
class BlueprintRepository
{
    public function __construct(protected string $root) {}

    public function root(): string
    {
        return $this->root;
    }

    public function ensureRoot(): void
    {
        foreach ([$this->root, $this->root.'/servers', $this->root.'/sites'] as $directory) {
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Server profiles
    |--------------------------------------------------------------------------
    */

    public function profiles(): array
    {
        return collect(glob($this->root.'/servers/*.json'))
            ->map(fn ($file) => basename($file, '.json'))
            ->values()
            ->toArray();
    }

    public function hasProfile(string $name): bool
    {
        return file_exists($this->profilePath($name));
    }

    public function profile(string $name): array
    {
        if (! $this->hasProfile($name)) {
            throw new RuntimeException("Server profile [{$name}] not found in {$this->profilePath($name)}.");
        }

        return array_replace(static::defaultProfile(), $this->readJson($this->profilePath($name)));
    }

    public function saveProfile(string $name, array $profile): void
    {
        $this->ensureRoot();
        $this->writeJson($this->profilePath($name), $profile);
    }

    public function profilePath(string $name): string
    {
        return $this->root.'/servers/'.$name.'.json';
    }

    public static function defaultProfile(string $type = 'app'): array
    {
        return [
            'type' => $type,
            'ubuntu_version' => '24.04',
            'php_version' => 'php84',
            'php_cli_version' => 'php84',
            'database_type' => null,
            'max_upload_size' => 5,
            'max_execution_time' => 30,
            'opcache' => true,
            'provider' => [],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Sites
    |--------------------------------------------------------------------------
    */

    public function sites(): array
    {
        return collect(glob($this->root.'/sites/*/site.json'))
            ->map(fn ($file) => basename(dirname($file)))
            ->values()
            ->toArray();
    }

    public function hasSite(string $domain): bool
    {
        return file_exists($this->sitePath($domain).'/site.json');
    }

    public function site(string $domain): array
    {
        if (! $this->hasSite($domain)) {
            throw new RuntimeException("Site blueprint [{$domain}] not found in {$this->sitePath($domain)}.");
        }

        return array_replace(static::defaultSite($domain), $this->readJson($this->sitePath($domain).'/site.json'));
    }

    public function saveSite(string $domain, array $site): void
    {
        $this->ensureSiteDirectory($domain);
        $this->writeJson($this->sitePath($domain).'/site.json', $site);
    }

    public function sitePath(string $domain): string
    {
        return $this->root.'/sites/'.$domain;
    }

    public static function defaultSite(string $domain): array
    {
        return [
            'domain' => $domain,
            'aliases' => [],
            'directory' => '/public',
            'project_type' => 'php',
            'php_version' => 'php84',
            'repository' => null,
            'lets_encrypt' => true,
            'ssl_domains' => [$domain],
            'quick_deploy' => false,
            'scheduler' => false,
            'workers' => [],
            'redirects' => [],
            'security' => [],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Environment and deployment files
    |--------------------------------------------------------------------------
    */

    /**
     * The site's shared .env with the instance's .env.{instance} overrides applied.
     */
    public function env(string $domain, ?string $instance = null): string
    {
        $env = $this->readFile($this->sitePath($domain).'/.env');

        if ($instance && file_exists($this->envOverridePath($domain, $instance))) {
            $env = EnvFile::merge($env, $this->readFile($this->envOverridePath($domain, $instance)));
        }

        return $env;
    }

    public function baseEnv(string $domain): string
    {
        return $this->readFile($this->sitePath($domain).'/.env');
    }

    public function saveEnv(string $domain, string $contents): void
    {
        $this->ensureSiteDirectory($domain);
        file_put_contents($this->sitePath($domain).'/.env', $contents);
    }

    public function saveEnvOverride(string $domain, string $instance, string $contents): void
    {
        $this->ensureSiteDirectory($domain);
        file_put_contents($this->envOverridePath($domain, $instance), $contents);
    }

    public function envOverridePath(string $domain, string $instance): string
    {
        return $this->sitePath($domain).'/.env.'.$instance;
    }

    public function deployScript(string $domain): string
    {
        return $this->readFile($this->sitePath($domain).'/deploy.sh');
    }

    public function saveDeployScript(string $domain, string $contents): void
    {
        $this->ensureSiteDirectory($domain);
        file_put_contents($this->sitePath($domain).'/deploy.sh', $contents);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function ensureSiteDirectory(string $domain): void
    {
        $this->ensureRoot();

        if (! is_dir($this->sitePath($domain))) {
            mkdir($this->sitePath($domain), 0755, true);
        }
    }

    protected function readFile(string $path): string
    {
        return file_exists($path) ? (string) file_get_contents($path) : '';
    }

    protected function readJson(string $path): array
    {
        $data = json_decode($this->readFile($path), true);

        if (! is_array($data)) {
            throw new RuntimeException("Unable to parse {$path}: ".json_last_error_msg());
        }

        return $data;
    }

    protected function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }
}
