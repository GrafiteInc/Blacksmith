<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Php;
use Grafite\Blacksmith\Support\Plan;
use Laravel\Forge\Resources\ServerTypes;

class SyncServer
{
    public function __construct(
        protected ForgeGateway $forge,
        protected BlueprintRepository $blueprints,
        protected StateRepository $state,
    ) {}

    public function handle(string $instance, Plan $plan): void
    {
        $serverId = (int) $this->state->instance($instance)['server_id'];
        $profile = $this->blueprints->profile($this->state->instance($instance)['profile']);

        if ($profile['type'] === ServerTypes::LOADBALANCER) {
            return;
        }

        $server = $this->forge->server($serverId);
        $label = "{$instance}: ";

        // Forge lists installed versions as 8.4 but names them php84 everywhere else.
        $installed = collect($this->forge->phpVersions($serverId))->map(fn ($php) => Php::name($php->version));

        foreach (array_unique([$profile['php_version'], $profile['php_cli_version']]) as $version) {
            if ($version && ! $installed->contains($version)) {
                $plan->step($label."Install {$version}", fn () => $this->forge->installPHP($serverId, $version));
            }
        }

        $opcache = $server->opcacheStatus === 'enabled';

        if ((bool) $profile['opcache'] !== $opcache) {
            $plan->step($label.($profile['opcache'] ? 'Enable' : 'Disable').' OPcache', fn () => $profile['opcache']
                ? $this->forge->enableOPCache($serverId)
                : $this->forge->disableOPCache($serverId));
        }

        $plan->step($label."Set upload limit {$profile['max_upload_size']}MB, execution time {$profile['max_execution_time']}s", fn () => $this->forge->updatePhpLimits($serverId, (int) $profile['max_upload_size'], (int) $profile['max_execution_time']));
    }
}
