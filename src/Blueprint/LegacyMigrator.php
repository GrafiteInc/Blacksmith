<?php

namespace Grafite\Blacksmith\Blueprint;

use Grafite\Blacksmith\Actions\PullFromForge;
use Grafite\Blacksmith\Actions\SyncWorkers;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\EnvFile;
use Grafite\Blacksmith\Support\ReportsProgress;
use Illuminate\Support\Str;

/**
 * Converts the v1 layout, .blacksmith/{serverId}/config.json (or .blacksmith/config.json),
 * into blueprints and state. The old files are moved to .blacksmith/legacy.
 */
class LegacyMigrator
{
    use ReportsProgress;

    public function __construct(protected BlueprintRepository $blueprints, protected StateRepository $state) {}

    public function detect(): array
    {
        $root = $this->blueprints->root();

        return collect(glob($root.'/*/config.json'))
            ->map(fn ($file) => dirname($file))
            ->reject(fn ($directory) => in_array(basename($directory), ['servers', 'sites', 'legacy'], true))
            ->when(file_exists($root.'/config.json'), fn ($directories) => $directories->prepend($root))
            ->values()
            ->toArray();
    }

    /**
     * @return array the names of the migrated instances
     */
    public function migrate(): array
    {
        $instances = [];
        $pools = [];

        foreach ($this->detect() as $directory) {
            $config = json_decode(file_get_contents($directory.'/config.json'), true) ?: [];
            $server = $config['server'] ?? [];
            $serverId = is_numeric($server['id'] ?? null) ? (int) $server['id'] : null;

            $instance = $this->uniqueInstanceName(Str::slug($server['name'] ?? '') ?: 'server-'.($serverId ?? Str::random(6)));
            $profile = PullFromForge::profileNameFor($server['type'] ?? null);

            if (! $this->blueprints->hasProfile($profile)) {
                $this->blueprints->saveProfile($profile, array_replace(BlueprintRepository::defaultProfile(($server['type'] ?? null) ?: 'app'), array_filter([
                    'php_version' => $server['php_version'] ?? null,
                    'php_cli_version' => $server['php_cli_version'] ?? $server['php_version'] ?? null,
                    'ubuntu_version' => $server['ubuntu_version'] ?? null,
                    'max_upload_size' => $server['max_upload_size'] ?? null,
                    'max_execution_time' => $server['max_execution_time'] ?? null,
                ]), ['opcache' => (bool) ($server['opcache_enabled'] ?? true)]));
            }

            $this->state->putInstance($instance, [
                'server_id' => $serverId,
                'ip_address' => $server['ip_address'] ?? null,
                'private_ip_address' => $server['private_ip_address'] ?? null,
                'profile' => $profile,
            ]);

            foreach ($config['sites'] ?? [] as $site) {
                $this->migrateSite($instance, $directory, $site);

                if (($server['type'] ?? null) === 'loadbalancer' && ! empty($site['balancing'])) {
                    $pools[$site['domain']] = [
                        'balancer' => $instance,
                        'nodes' => collect($site['balancing'])->pluck('server_id')->toArray(),
                        'method' => str_replace('-', '_', $site['balancing_type'] ?? 'round_robin'),
                    ];
                }
            }

            $this->archive($directory);
            $this->report("Migrated {$directory} to instance {$instance}");

            $instances[] = $instance;
        }

        foreach ($pools as $domain => $pool) {
            $nodes = collect($pool['nodes'])->map(fn ($id) => $this->state->instanceForServer($id))->filter()->values();

            $this->state->putPool($domain, [
                'balancer' => $pool['balancer'],
                'primary' => $nodes->first(),
                'nodes' => $nodes->toArray(),
                'method' => $pool['method'],
            ]);
        }

        return $instances;
    }

    protected function migrateSite(string $instance, string $directory, array $site): void
    {
        $domain = $site['domain'];
        $env = $this->read($directory.'/'.($site['environment_variables_file'] ?? $domain.'.env'));

        if (is_numeric($site['id'] ?? null)) {
            $this->state->setSiteId($instance, $domain, (int) $site['id']);
        }

        if ($this->blueprints->hasSite($domain)) {
            $diff = EnvFile::diff($this->blueprints->baseEnv($domain), $env);

            if ($diff !== '') {
                $this->blueprints->saveEnvOverride($domain, $instance, $diff);
            }

            return;
        }

        $repository = $site['repository'] ?? null;

        if (empty($repository['repository']) || $repository['repository'] === 'username/repository') {
            $repository = null;
        }

        $this->blueprints->saveSite($domain, array_replace(BlueprintRepository::defaultSite($domain), [
            'directory' => $site['directory'] ?? '/public',
            'php_version' => $site['php_version'] ?? 'php84',
            'repository' => $repository,
            'lets_encrypt' => (bool) ($site['lets_encrypt'] ?? true),
            'ssl_domains' => $site['ssl_domains'] ?? [$domain],
            'quick_deploy' => (bool) ($site['enable_quick_deploy'] ?? false),
            'scheduler' => (bool) ($site['scheduler_enabled'] ?? false),
            'workers' => collect($site['workers'] ?? [])->map(fn ($worker) => SyncWorkers::normalize($worker))->values()->toArray(),
            'redirects' => collect($site['redirects'] ?? [])
                ->filter(fn ($rule) => ! empty($rule['from']))
                ->map(fn ($rule) => [
                    'from' => $rule['from'],
                    'to' => $rule['to'],
                    'type' => $rule['type'] ?? ((int) ($rule['code'] ?? 302) === 301 ? 'permanent' : 'redirect'),
                ])->values()->toArray(),
            'security' => collect($site['security'] ?? [])
                ->filter(fn ($rule) => ! empty($rule['name']))
                ->map(fn ($rule) => [
                    'name' => $rule['name'],
                    'path' => $rule['path'] ?? null,
                    'credentials' => collect(isset($rule['credentials']['username']) ? [$rule['credentials']] : ($rule['credentials'] ?? []))
                        ->map(fn ($credential) => ['username' => $credential['username'] ?? '', 'password' => $credential['password'] ?? ''])
                        ->values()->toArray(),
                ])->values()->toArray(),
        ]));

        $this->blueprints->saveEnv($domain, $env);
        $this->blueprints->saveDeployScript($domain, $this->read($directory.'/'.($site['deployment_file'] ?? $domain.'.deploy')));
    }

    protected function archive(string $directory): void
    {
        $root = $this->blueprints->root();
        $target = $root.'/legacy/'.($directory === $root ? 'root' : basename($directory));

        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }

        $files = $directory === $root
            ? array_merge([$root.'/config.json'], glob($root.'/*.env') ?: [], glob($root.'/*.deploy') ?: [])
            : glob($directory.'/{,.}*', GLOB_BRACE);

        foreach ($files as $file) {
            if (is_file($file)) {
                rename($file, $target.'/'.basename($file));
            }
        }

        if ($directory !== $root) {
            @rmdir($directory);
        }
    }

    protected function uniqueInstanceName(string $name): string
    {
        $candidate = $name;
        $number = 2;

        while ($this->state->hasInstance($candidate)) {
            $candidate = $name.'-'.$number++;
        }

        return $candidate;
    }

    protected function read(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
