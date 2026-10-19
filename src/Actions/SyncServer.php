<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Plan;
use Laravel\Forge\Forge;
use Laravel\Forge\Resources\ServerTypes;

class SyncServer
{
    public function __construct(
        protected Forge $forge,
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

        $installed = collect($this->forge->phpVersions($serverId))->pluck('version');

        foreach (array_unique([$profile['php_version'], $profile['php_cli_version']]) as $version) {
            if ($version && ! $installed->contains($version)) {
                $plan->step($label."Install {$version}", fn () => $this->forge->installPHP($serverId, $version));
            }
        }

        $opcache = ($server->attributes['opcache_status'] ?? null) === 'enabled';

        if ((bool) $profile['opcache'] !== $opcache) {
            $plan->step($label.($profile['opcache'] ? 'Enable' : 'Disable').' OPcache', fn () => $profile['opcache']
                ? $this->forge->enableOPCache($serverId)
                : $this->forge->disableOPCache($serverId));
        }

        $plan->step($label."Set upload limit {$profile['max_upload_size']}MB, execution time {$profile['max_execution_time']}s", fn () => $this->forge->updateServer($serverId, [
            'max_upload_size' => $profile['max_upload_size'],
            'max_execution_time' => $profile['max_execution_time'],
        ]));
    }
}
