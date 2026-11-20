<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Plan;
use RuntimeException;

/**
 * Deploy a load balanced site one node at a time.
 *
 * Each node is marked down on the balancer, deployed and waited on before the
 * next one goes, so the others keep serving throughout. Web nodes go first and
 * the primary, which runs the scheduler and workers, goes last. A node whose
 * deployment fails stays down while the rest are back in the pool.
 */
class RollingDeploy
{
    public function __construct(
        protected ForgeGateway $forge,
        protected StateRepository $state,
        protected ConfigureBalancer $balancer,
    ) {}

    public function handle(string $domain, Plan $plan): void
    {
        $pool = $this->state->pool($domain);

        if (! $pool || ! $pool['balancer']) {
            throw new RuntimeException("{$domain} does not have a load balancer, a rolling deploy needs one.");
        }

        $nodes = collect($pool['nodes'])
            ->sortBy(fn ($node) => $node === $pool['primary'] ? 1 : 0)
            ->values();

        $drain = $nodes->count() > 1;

        if (! $drain) {
            $plan->note("{$domain}: Only one node, so it deploys without being taken out of the balancer.");
        }

        foreach ($nodes as $node) {
            $serverId = (int) ($this->state->instance($node)['server_id'] ?? 0);
            $siteId = $this->state->siteId($node, $domain);

            if ((! $serverId || ! $siteId) && ! $plan->dryRun) {
                throw new RuntimeException("{$node} has no {$domain} site yet, run blacksmith:update-server {$node} first.");
            }

            if ($drain) {
                $this->balancer->handle($domain, $plan, [$node]);
            }

            try {
                $plan->step("{$node} {$domain}: Deploy and wait", fn () => $this->forge->deployAndWait($serverId, $siteId));
            } catch (RuntimeException $exception) {
                // The balancer already has every other node up, so the failed node simply stays out.
                $state = $drain ? "{$node} stays down on the balancer while the other nodes serve {$domain}." : '';

                throw new RuntimeException(trim("{$node} {$domain}: {$exception->getMessage()}".PHP_EOL.$state).PHP_EOL
                    .'Fix it, then run the rolling deploy again.', 0, $exception);
            }
        }

        if ($drain) {
            $this->balancer->handle($domain, $plan);
        }
    }
}
