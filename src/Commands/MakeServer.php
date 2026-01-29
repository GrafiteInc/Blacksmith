<?php

namespace Grafite\Blacksmith\Commands;

use DigitalOceanV2\Client;
use Illuminate\Console\Command;

class MakeServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:make-server {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new server via the provider.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (config('blacksmith.server_provider') === 'digitalocean') {
            $client = new Client;
            $client->authenticate(config('blacksmith.server_provider_token'));
            $key = $client->key();

            $droplet = $client->droplet();
            $created = $droplet->create(
                $this->argument('name'),
                config('blacksmith.server_provider_options.digitalocean.region'),
                config('blacksmith.server_provider_options.digitalocean.size'),
                config('blacksmith.server_provider_options.digitalocean.image'),
                config('blacksmith.server_provider_options.digitalocean.backups'),
                config('blacksmith.server_provider_options.digitalocean.ipv6'),
                false,
                collect($key->getAll())->pluck('id')->toArray(),
                '',
                config('blacksmith.server_provider_options.digitalocean.monitoring'),
                [],
                ['blacksmith']
            );

            foreach (range(1, 8) as $seconds) {
                $this->info('Waiting for server to be provisioned... '.$seconds.'0 seconds elapsed.');
                sleep(10);
            }

            $ipAddress = $droplet->getById($created->id)->networks[0]->ipAddress;

            if ($ipAddress) {
                $provision = $this->question('Server created with IP: '.$ipAddress.'. Do you want to provision with Forge?', 'info');

                if ($provision) {
                    $this->call('blacksmith:build-server', [
                        'ip' => $ipAddress,
                        '--name' => $this->argument('name'),
                        '--type' => 'app',
                        '--php' => 'php84',
                        '--ubuntu' => '24.04',
                        '--private_ip' => $droplet->getById($created->id)->networks[1]->ipAddress ?? null,
                    ]);
                } else {
                    $this->info('You can provision this server later via Forge using the IP: '.$ipAddress);
                }
            } else {
                $this->error('Server created but no IP found yet. Please check your provider dashboard.');
            }
        } else {
            $this->error('Unsupported server provider configured.');

            return 1;
        }

        return 0;
    }
}
