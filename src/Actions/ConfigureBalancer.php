<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Defaults;
use Grafite\Blacksmith\Support\Plan;
use RuntimeException;

class ConfigureBalancer
{
    public const METHODS = ['round_robin', 'least_conn', 'ip_hash'];

    public function __construct(protected ForgeGateway $forge, protected StateRepository $state) {}

    /**
     * Point the balancer's site at the pool's nodes, any node in $down is drained.
     * $instance targets another balancer than the pool's, such as its replacement.
     */
    public function handle(string $domain, Plan $plan, array $down = [], ?string $instance = null): void
    {
        $pool = $this->state->pool($domain);

        if (! $pool || ! $pool['balancer']) {
            throw new RuntimeException("{$domain} does not have a load balancer, run blacksmith:balancer first.");
        }

        $instance ??= $pool['balancer'];
        $balancer = $this->state->instance($instance);
        $siteId = $this->state->siteId($instance, $domain);

        if (! $siteId && ! $plan->dryRun) {
            throw new RuntimeException("{$domain} has not been created on {$instance} yet.");
        }

        $servers = collect($pool['nodes'])->map(fn ($node) => [
            'server_id' => (int) $this->state->instance($node)['server_id'],
            'weight' => (int) ($pool['options'][$node]['weight'] ?? Defaults::get('balancer.weight', 1)),
            'down' => in_array($node, $down, true),
            'backup' => (bool) ($pool['options'][$node]['backup'] ?? Defaults::get('balancer.backup', false)),
            'port' => (int) ($pool['options'][$node]['port'] ?? Defaults::get('balancer.port', 80)),
        ])->values()->toArray();

        $nodes = collect($pool['nodes'])->map(fn ($node) => in_array($node, $down, true) ? "{$node} (down)" : $node)->implode(', ');

        $plan->step("{$instance}: balance {$domain} across {$nodes} ({$pool['method']})", function () use ($balancer, $siteId, $servers, $pool) {
            $this->forge->updateBalancingNodes((int) $balancer['server_id'], $siteId, $servers, $pool['method']);
        });
    }
}
