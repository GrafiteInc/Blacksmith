<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Plan;
use Laravel\Forge\Forge;

/**
 * A new server with the given site blueprints pushed to it and deployed.
 */
class BuildInstance
{
    public function __construct(
        protected Forge $forge,
        protected StateRepository $state,
        protected ProvisionServer $provision,
        protected SyncServer $server,
        protected SyncSite $site,
    ) {}

    public function reportUsing(?callable $reporter): static
    {
        $this->provision->reportUsing($reporter);

        return $this;
    }

    public function handle(
        string $name,
        string $profile,
        array $sites,
        Plan $plan,
        ?string $ip = null,
        ?string $privateIp = null,
        ?string $role = null,
        bool $deploy = true,
    ): array {
        $this->provision->handle($name, $profile, $ip, $privateIp);

        $this->server->handle($name, $plan);

        foreach ($sites as $domain) {
            $siteId = $this->site->handle($name, $domain, $plan, [], $role);
            $siteRole = $role ?? $this->state->roleFor($name, $domain);

            if ($deploy && $siteId && $siteRole !== 'balancer') {
                $serverId = $this->state->instance($name)['server_id'];

                $plan->step("{$name} {$domain}: Deploy", fn () => $this->forge->deploySite($serverId, $siteId, false));
            }
        }

        return $this->state->instance($name);
    }
}
