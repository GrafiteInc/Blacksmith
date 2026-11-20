<?php

namespace Grafite\Blacksmith\State;

use Grafite\Blacksmith\Support\Defaults;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Maps blueprints onto real Forge resources.
 *
 * {
 *   "instances": {
 *     "prod": {"server_id": 1, "ip_address": "", "private_ip_address": "", "profile": "app",
 *              "provider": "digitalocean", "provider_id": 2, "sites": {"example.com": 3}},
 *     "prod-next": {"server_id": 4, ..., "replaces": "prod"},     # being built by blacksmith:replace
 *     "prod-old": {"server_id": 5, ..., "retired_from": "prod"}   # replaced, waiting for --finish
 *   },
 *   "pools": {
 *     "example.com": {"balancer": "lb", "primary": "prod", "nodes": ["prod", "example-com-node-1"], "method": "round_robin"}
 *   },
 *   "dns": {
 *     "example.com": {"zone_id": "023e105f4ecef8ad9ca31a8372d0c353"}  # saved by blacksmith:localize
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

        $this->state = array_replace(['instances' => [], 'pools' => [], 'dns' => []], is_array($state) ? $state : []);
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

    /*
    |--------------------------------------------------------------------------
    | Replacements
    |--------------------------------------------------------------------------
    */

    /**
     * The instance being built to replace $instance, if any.
     */
    public function replacementFor(string $instance): ?string
    {
        return $this->findInstance(fn ($record) => ($record['replaces'] ?? null) === $instance);
    }

    /**
     * The old server $instance was moved off of, kept until the replacement is finished.
     */
    public function retiredFor(string $instance): ?string
    {
        return $this->findInstance(fn ($record) => ($record['retired_from'] ?? null) === $instance);
    }

    /**
     * Replacements and retired servers are mid-move and are left out of bulk commands.
     */
    public function isTransient(string $instance): bool
    {
        $record = $this->state['instances'][$instance] ?? [];

        return ! empty($record['replaces']) || ! empty($record['retired_from']);
    }

    /**
     * The instance name a server's blueprints (such as .env.{instance}) are read under.
     */
    public function blueprintName(string $instance): string
    {
        return $this->state['instances'][$instance]['replaces'] ?? $instance;
    }

    /**
     * Swap the replacement in under the instance's name and keep the old server as $retired,
     * in one write, pools and env overrides refer to names so they follow the new server.
     */
    public function promoteReplacement(string $instance, string $retired): void
    {
        $replacement = $this->replacementFor($instance);

        if (! $replacement) {
            throw new RuntimeException("Instance [{$instance}] has no replacement.");
        }

        if ($this->hasInstance($retired)) {
            throw new RuntimeException("Instance [{$retired}] already exists.");
        }

        $new = $this->state['instances'][$replacement];
        unset($new['replaces'], $this->state['instances'][$replacement]);

        $this->state['instances'][$retired] = array_replace($this->instance($instance), ['retired_from' => $instance]);
        $this->state['instances'][$instance] = $new;

        $this->save();
    }

    protected function findInstance(callable $matches): ?string
    {
        foreach ($this->state['instances'] as $name => $record) {
            if ($matches($record)) {
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

        return collect($this->instancesFor($domain))->first(fn ($instance) => ! $this->isTransient($instance));
    }

    /**
     * primary: full copy of the site (scheduler, workers)
     * web: a load balanced node that only serves web traffic
     * balancer: the load balancer in front of the nodes
     *
     * A replacement or retired server never runs the scheduler or workers.
     */
    public function roleFor(string $instance, string $domain): string
    {
        $record = $this->state['instances'][$instance] ?? [];

        if ($original = $record['replaces'] ?? $record['retired_from'] ?? null) {
            return $this->roleFor($original, $domain) === 'balancer' ? 'balancer' : 'web';
        }

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
            'method' => Defaults::get('balancer.method', 'round_robin'),
        ], $this->state['pools'][$domain] ?? [], $pool);

        $this->save();
    }

    public function isPooled(string $instance, string $domain): bool
    {
        $pool = $this->pool($domain);

        return $pool && in_array($instance, $pool['nodes'], true);
    }

    /**
     * The sites whose DNS points straight at the instance, rather than at a load balancer in front of it.
     */
    public function publicSitesFor(string $instance): array
    {
        return collect($this->sitesFor($instance))
            ->filter(fn ($domain) => match ($this->roleFor($instance, $domain)) {
                'balancer' => true,
                'primary' => ! $this->isPooled($instance, $domain),
                default => false,
            })
            ->values()
            ->toArray();
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

    /*
    |--------------------------------------------------------------------------
    | DNS
    |--------------------------------------------------------------------------
    */

    /**
     * domain => zone ID for every site whose DNS zone has been found.
     */
    public function dnsZones(): array
    {
        return collect($this->state['dns'])
            ->map(fn ($record) => $record['zone_id'] ?? null)
            ->filter()
            ->toArray();
    }

    public function dnsZone(string $domain): ?string
    {
        return $this->state['dns'][$domain]['zone_id'] ?? null;
    }

    public function putDnsZone(string $domain, string $zoneId): void
    {
        $this->state['dns'][$domain] = array_replace($this->state['dns'][$domain] ?? [], ['zone_id' => $zoneId]);
        $this->save();
    }

    /**
     * The addresses of every instance, the only ones Blacksmith moves DNS away from without --force.
     */
    public function knownIps(): array
    {
        return collect($this->state['instances'])
            ->flatMap(fn ($instance) => [$instance['ip_address'] ?? null])
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    public function instanceForIp(string $ip): ?string
    {
        return $this->findInstance(fn ($record) => ($record['ip_address'] ?? null) === $ip);
    }

    public function save(): void
    {
        if (! is_dir(dirname($this->path))) {
            mkdir(dirname($this->path), 0755, true);
        }

        file_put_contents($this->path, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }
}
