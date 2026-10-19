<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\ServerProviders\ServerProvider;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Poll;
use Grafite\Blacksmith\Support\RemoteShell;
use Grafite\Blacksmith\Support\ReportsProgress;
use Illuminate\Support\Str;
use Laravel\Forge\Forge;
use Laravel\Forge\Resources\ServerProviders;
use Laravel\Forge\Resources\ServerTypes;
use RuntimeException;

/**
 * Create a box (or use an existing IP), connect it to Forge as a custom
 * server, run Forge's provision script over SSH and wait until it's ready.
 */
class ProvisionServer
{
    use ReportsProgress;

    public function __construct(
        protected Forge $forge,
        protected BlueprintRepository $blueprints,
        protected StateRepository $state,
        protected RemoteShell $shell,
    ) {}

    public function handle(string $instance, string $profileName, ?string $ip = null, ?string $privateIp = null): array
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

            $this->report("{$instance}: Creating server with {$provider->name()}");
            $providerId = $provider->create($instance, $profile['provider'] ?? []);

            $this->state->putInstance($instance, [
                'server_id' => null,
                'profile' => $profileName,
                'provider' => $provider->name(),
                'provider_id' => $providerId,
            ]);

            $this->report("{$instance}: Waiting for the server to boot");
            $addresses = $provider->waitUntilActive($providerId);

            $ip = $addresses['ip_address'];
            $privateIp ??= $addresses['private_ip_address'];
        }

        $this->report("{$instance}: Waiting for SSH on {$ip}");
        $this->shell->waitUntilReachable($ip);

        $payload = [
            'provider' => ServerProviders::CUSTOM,
            'name' => $instance,
            'type' => $profile['type'],
            'ubuntu_version' => $profile['ubuntu_version'],
            'ip_address' => $ip,
            'private_ip_address' => $privateIp,
        ];

        if ($profile['type'] !== ServerTypes::LOADBALANCER) {
            $payload['php_version'] = $profile['php_version'];
        }

        if ($profile['database_type']) {
            $payload['database_type'] = $profile['database_type'];
        }

        $this->report("{$instance}: Creating the Forge server");
        $server = $this->forge->createServer($payload);

        $this->state->putInstance($instance, [
            'server_id' => $server->id,
            'ip_address' => $ip,
            'private_ip_address' => $privateIp,
            'profile' => $profileName,
        ]);

        $this->report("{$instance}: Running the Forge provision script, this takes around 10 minutes");
        $result = $this->shell->run($ip, $server->provisionCommand, (int) config('blacksmith.provision_timeout', 1800));

        if (! $result->successful()) {
            throw new RuntimeException("{$instance}: The provision script failed.".PHP_EOL.Str::of($result->errorOutput() ?: $result->output())->limit(2000));
        }

        $this->waitUntilReady($instance, $server->id);

        $this->report("{$instance}: Server ready https://forge.laravel.com/servers/{$server->id}");

        return $this->state->instance($instance);
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
