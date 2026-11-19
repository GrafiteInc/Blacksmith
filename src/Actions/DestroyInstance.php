<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\ServerProviders\ServerProvider;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Plan;

/**
 * Delete an instance's Forge server and provider server, then forget it.
 * Works on half built instances that never got a Forge server.
 */
class DestroyInstance
{
    public function __construct(protected ForgeGateway $forge, protected StateRepository $state) {}

    public function handle(string $name, Plan $plan): void
    {
        $instance = $this->state->instance($name);

        if (! empty($instance['server_id'])) {
            $plan->step("{$name}: Delete Forge server", fn () => $this->forge->deleteServer($instance['server_id']));
        }

        if (! empty($instance['provider_id'])) {
            $plan->step("{$name}: Destroy {$instance['provider']} server", fn () => app(ServerProvider::class)->destroy($instance['provider_id']));
        }

        if (! $plan->dryRun) {
            $this->state->forgetInstance($name);
        }
    }
}
