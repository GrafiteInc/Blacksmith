<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Plan;
use Laravel\Forge\Forge;
use RuntimeException;

class ConfigureBalancer
{
    public const METHODS = ['round_robin', 'least_conn', 'ip_hash'];

    public function __construct(protected Forge $forge, protected StateRepository $state) {}

    /**
     * Point the balancer's site at the pool's nodes, any node in $down is drained.
     */
    public function handle(string $domain, Plan $plan, array $down = []): void
    {
        $pool = $this->state->pool($domain);

        if (! $pool || ! $pool['balancer']) {
            throw new RuntimeException("{$domain} does not have a load balancer, run blacksmith:balancer first.");
        }

        $balancer = $this->state->instance($pool['balancer']);
        $siteId = $this->state->siteId($pool['balancer'], $domain);

        if (! $siteId && ! $plan->dryRun) {
            throw new RuntimeException("{$domain} has not been created on {$pool['balancer']} yet.");
        }

        $servers = collect($pool['nodes'])->map(fn ($node) => [
            'id' => $this->state->instance($node)['server_id'],
            'weight' => $pool['options'][$node]['weight'] ?? 1,
            'down' => in_array($node, $down, true),
            'backup' => $pool['options'][$node]['backup'] ?? false,
            'port' => $pool['options'][$node]['port'] ?? 80,
        ])->values()->toArray();

        $nodes = collect($pool['nodes'])->map(fn ($node) => in_array($node, $down, true) ? "{$node} (down)" : $node)->implode(', ');

        $plan->step("{$pool['balancer']}: balance {$domain} across {$nodes} ({$pool['method']})", function () use ($balancer, $siteId, $servers, $pool) {
            $this->forge->updateNodeBalancingConfiguration($balancer['server_id'], $siteId, [
                'servers' => $servers,
                'method' => $pool['method'],
            ]);
        });
    }
}
