<?php

namespace Grafite\Blacksmith\State;

use Illuminate\Support\Str;
use RuntimeException;

/**
 * Maps blueprints onto real Forge resources.
 *
 * {
 *   "instances": {
 *     "prod": {"server_id": 1, "ip_address": "", "private_ip_address": "", "profile": "app",
 *              "provider": "digitalocean", "provider_id": 2, "sites": {"example.com": 3}}
 *   },
 *   "pools": {
 *     "example.com": {"balancer": "lb", "primary": "prod", "nodes": ["prod", "example-com-node-1"], "method": "round_robin"}
 *   }
 * }
 *
 * Every mutation is written straight to disk so a command that fails half way
 * still leaves an accurate record of what was created.
 */
class StateRepository
{
    protected array $state;

    public function __construct(protected string $path)
    {
        $this->reload();
    }

    public function reload(): void
    {
        $state = file_exists($this->path) ? json_decode(file_get_contents($this->path), true) : null;

        $this->state = array_replace(['instances' => [], 'pools' => []], is_array($state) ? $state : []);
    }

    public function exists(): bool
    {
        return file_exists($this->path);
    }

    public function all(): array
    {
        return $this->state;
    }

    /*
    |--------------------------------------------------------------------------
    | Instances
    |--------------------------------------------------------------------------
    */

    public function instances(): array
    {
        return $this->state['instances'];
    }

    public function hasInstance(string $name): bool
    {
        return isset($this->state['instances'][$name]);
    }

    public function instance(string $name): array
    {
        if (! $this->hasInstance($name)) {
            throw new RuntimeException("Instance [{$name}] not found in {$this->path}.");
        }

        return $this->state['instances'][$name];
    }

    public function putInstance(string $name, array $instance): void
    {
        $existing = $this->state['instances'][$name] ?? ['sites' => []];

        $this->state['instances'][$name] = array_replace($existing, $instance);
        $this->save();
    }

    public function forgetInstance(string $name): void
    {
        unset($this->state['instances'][$name]);
        $this->save();
    }

    public function instanceForServer(int|string $serverId): ?string
    {
        foreach ($this->state['instances'] as $name => $instance) {
            if ((string) $instance['server_id'] === (string) $serverId) {
                return $name;
            }
        }

        return null;
    }

    public function siteId(string $instance, string $domain): ?int
    {
        $id = $this->state['instances'][$instance]['sites'][$domain] ?? null;

        return is_null($id) ? null : (int) $id;
    }

    public function setSiteId(string $instance, string $domain, int|string $siteId): void
    {
        $this->state['instances'][$instance]['sites'][$domain] = (int) $siteId;
        $this->save();
    }

    public function sitesFor(string $instance): array
    {
        return array_keys($this->state['instances'][$instance]['sites'] ?? []);
    }

    /**
     * The instances that currently host the site, in the order they were added.
     */
    public function instancesFor(string $domain): array
    {
        return collect($this->state['instances'])
            ->filter(fn ($instance) => isset($instance['sites'][$domain]))
            ->keys()
            ->toArray();
    }

    /**
     * The instance whose configuration is the source of truth for the site blueprint.
     */
    public function primaryInstanceFor(string $domain): ?string
    {
        if ($pool = $this->pool($domain)) {
            return $pool['primary'];
        }

        return $this->instancesFor($domain)[0] ?? null;
    }

    /**
     * primary: full copy of the site (scheduler, workers)
     * web: a load balanced node that only serves web traffic
     * balancer: the load balancer in front of the nodes
     */
    public function roleFor(string $instance, string $domain): string
    {
        $pool = $this->pool($domain);

        if ($pool && $pool['balancer'] === $instance) {
            return 'balancer';
        }

        if ($pool && $pool['primary'] !== $instance && in_array($instance, $pool['nodes'], true)) {
            return 'web';
        }

        return 'primary';
    }

    /*
    |--------------------------------------------------------------------------
    | Pools
    |--------------------------------------------------------------------------
    */

    public function pools(): array
    {
        return $this->state['pools'];
    }

    public function pool(string $domain): ?array
    {
        return $this->state['pools'][$domain] ?? null;
    }

    public function putPool(string $domain, array $pool): void
    {
        $this->state['pools'][$domain] = array_replace([
            'balancer' => null,
            'primary' => null,
            'nodes' => [],
            'method' => 'round_robin',
        ], $this->state['pools'][$domain] ?? [], $pool);

        $this->save();
    }

    public function nextNodeName(string $domain): string
    {
        $prefix = Str::slug(str_replace('.', '-', $domain)).'-node-';
        $number = 1;

        while ($this->hasInstance($prefix.$number)) {
            $number++;
        }

        return $prefix.$number;
    }

    public function save(): void
    {
        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0755, true);
        }

        file_put_contents($this->path, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }
}
