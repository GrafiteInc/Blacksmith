<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\ServerProviders\ForgeManagedProvider;
use Grafite\Blacksmith\ServerProviders\ServerProvider;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Poll;
use Grafite\Blacksmith\Support\RemoteShell;
use Grafite\Blacksmith\Support\ReportsProgress;
use Illuminate\Support\Str;
use Laravel\Forge\Resources\ServerProviders;
use Laravel\Forge\Resources\ServerTypes;
use RuntimeException;

/**
 * Get a new Forge server ready.
 *
 * On a provider Forge manages (DigitalOcean), Forge creates and provisions the
 * server itself. Otherwise the box (an existing IP, or one from the provider)
 * is connected to Forge as a custom server and provisioned over SSH.
 */
class ProvisionServer
{
    use ReportsProgress;

    public function __construct(
        protected ForgeGateway $forge,
        protected BlueprintRepository $blueprints,
        protected StateRepository $state,
        protected RemoteShell $shell,
    ) {}

    /**
     * @param  array  $attributes  extra values stored on the instance in state.json
     */
    public function handle(string $instance, string $profileName, ?string $ip = null, ?string $privateIp = null, array $attributes = []): array
    {
        if ($this->state->hasInstance($instance)) {
            $existing = $this->state->instance($instance);

            throw new RuntimeException(empty($existing['server_id'])
                ? "Instance [{$instance}] is left over from a failed run ({$existing['provider']} server {$existing['provider_id']}), destroy it and remove it from state.json first."
                : "Instance [{$instance}] already exists.");
        }

        $profile = $this->blueprints->profile($profileName);

        if (! $ip) {
            $provider = app(ServerProvider::class);

            if ($provider instanceof ForgeManagedProvider) {
                return $this->createWithForge($instance, $profileName, $profile, $provider, $attributes);
            }

            $this->report("{$instance}: Creating server with {$provider->name()}");
            $providerId = $provider->create($instance, $profile['provider'] ?? []);

            $this->state->putInstance($instance, array_replace($attributes, [
                'server_id' => null,
                'profile' => $profileName,
                'provider' => $provider->name(),
                'provider_id' => $providerId,
            ]));

            $this->report("{$instance}: Waiting for the server to boot");
            $addresses = $provider->waitUntilActive($providerId);

            $ip = $addresses['ip_address'];
            $privateIp ??= $addresses['private_ip_address'];
        }

        return $this->connectCustomServer($instance, $profileName, $profile, $ip, $privateIp, $attributes);
    }

    /**
     * Forge creates the server at the provider and provisions it, and deletes it there with the Forge server.
     */
    protected function createWithForge(string $instance, string $profileName, array $profile, ServerProvider&ForgeManagedProvider $provider, array $attributes): array
    {
        $this->report("{$instance}: Creating the Forge server on {$provider->name()}");
        $server = $this->forge->createServer(array_replace(
            $this->payload($instance, $profile),
            $provider->forgePayload($this->forge, $profile['provider'] ?? []),
        ));

        // No provider_id: deleting the Forge server deletes it at the provider too.
        $this->state->putInstance($instance, array_replace($attributes, [
            'server_id' => $server->id,
            'profile' => $profileName,
            'provider' => $provider->name(),
        ]));

        $this->report("{$instance}: Forge is creating and provisioning the server, this takes around 10 minutes");
        $this->waitUntilReady($instance, $server->id);

        $ready = $this->forge->server($server->id);

        $this->state->putInstance($instance, [
            'ip_address' => $ready->ipAddress,
            'private_ip_address' => $ready->privateIpAddress,
        ]);

        $this->report("{$instance}: Server ready https://forge.laravel.com/servers/{$server->id}");

        return $this->state->instance($instance);
    }

    protected function connectCustomServer(string $instance, string $profileName, array $profile, string $ip, ?string $privateIp, array $attributes): array
    {
        $this->report("{$instance}: Waiting for SSH on {$ip}");
        $this->shell->waitUntilReachable($ip);

        $this->report("{$instance}: Creating the Forge server");
        $server = $this->forge->createCustomServer(array_replace($this->payload($instance, $profile), [
            'provider' => ServerProviders::CUSTOM,
            'ip_address' => $ip,
            'private_ip_address' => $privateIp,
        ]));

        $this->state->putInstance($instance, array_replace($attributes, [
            'server_id' => $server->id,
            'ip_address' => $ip,
            'private_ip_address' => $privateIp,
            'profile' => $profileName,
        ]));

        if ($server->provisionCommand) {
            $this->report("{$instance}: Running the Forge provision script, this takes around 10 minutes");
            $result = $this->shell->run($ip, $server->provisionCommand, (int) config('blacksmith.provision_timeout', 1800));

            if (! $result->successful()) {
                throw new RuntimeException("{$instance}: The provision script failed.".PHP_EOL.Str::of($result->errorOutput() ?: $result->output())->limit(2000));
            }
        } else {
            // Forge's API v2 leaves the provision command out, it's only shown in the dashboard.
            $this->report("{$instance}: Run the provision command from https://forge.laravel.com/servers/{$server->id} on {$ip} as root, waiting for Forge to report the server as ready", 'warn');
        }

        $this->waitUntilReady($instance, $server->id);

        $this->report("{$instance}: Server ready https://forge.laravel.com/servers/{$server->id}");

        return $this->state->instance($instance);
    }

    /**
     * The profile's part of Forge's create server request.
     */
    protected function payload(string $instance, array $profile): array
    {
        $payload = [
            'name' => $instance,
            'type' => $profile['type'],
            'ubuntu_version' => $profile['ubuntu_version'],
        ];

        if ($profile['type'] !== ServerTypes::LOADBALANCER) {
            $payload['php_version'] = $profile['php_version'];
        }

        if ($profile['database_type']) {
            $payload['database_type'] = $profile['database_type'];
        }

        return $payload;
    }

    protected function waitUntilReady(string $instance, int $serverId): void
    {
        $timeout = (int) config('blacksmith.provision_timeout', 1800);

        $ready = Poll::until(fn () => $this->forge->server($serverId)->isReady, $timeout, (int) config('blacksmith.poll_interval', 15));

        if (! $ready) {
            throw new RuntimeException("{$instance}: Forge did not report the server as ready within {$timeout} seconds.");
        }
    }
}
