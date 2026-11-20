<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Plan;
use RuntimeException;

/**
 * A new server with the given site blueprints pushed to it and deployed.
 *
 * With $dns each site's DNS is pointed at the new server before its
 * certificate is requested, so Let's Encrypt can reach it. Each deploy is
 * waited on, so callers only see the instance once its code is live.
 */
class BuildInstance
{
    public function __construct(
        protected ForgeGateway $forge,
        protected StateRepository $state,
        protected ProvisionServer $provision,
        protected SyncServer $server,
        protected SyncSite $site,
        protected PointDns $dns,
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
        array $attributes = [],
        bool $dns = false,
    ): array {
        $this->provision->handle($name, $profile, $ip, $privateIp, $attributes);

        $this->server->handle($name, $plan);

        foreach ($sites as $domain) {
            $siteRole = $role ?? $this->state->roleFor($name, $domain);
            $pointDns = $dns && $siteRole !== 'web' && $this->dns->manages($domain);

            if ($pointDns) {
                $siteId = $this->site->handle($name, $domain, $plan, array_values(array_diff(SyncSite::SECTIONS, ['ssl'])), $role);
                $this->dns->handle($domain, $name, $plan);
                $this->site->handle($name, $domain, $plan, ['ssl'], $role);
            } else {
                $siteId = $this->site->handle($name, $domain, $plan, [], $role);
            }

            if ($deploy && $siteId && $siteRole !== 'balancer') {
                $serverId = $this->state->instance($name)['server_id'];

                // Waiting keeps a new node out of the balancer, and a replacement out of cutover, until its code is live.
                $plan->step("{$name} {$domain}: Deploy", function () use ($name, $domain, $serverId, $siteId) {
                    try {
                        return $this->forge->deployAndWait($serverId, $siteId);
                    } catch (RuntimeException $exception) {
                        throw new RuntimeException("{$name} {$domain}: {$exception->getMessage()}".PHP_EOL
                            ."Fix it, then deploy again with: php artisan blacksmith:update-server {$name} --site={$domain} --deploy", 0, $exception);
                    }
                });
            }
        }

        return $this->state->instance($name);
    }
}
