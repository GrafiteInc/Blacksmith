<?php

namespace Grafite\Blacksmith\Commands;

use Laravel\Forge\Forge;
use Illuminate\Console\Command;
use Laravel\Forge\Resources\ServerTypes;
use Laravel\Forge\Resources\ServerProviders;

class BuildServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:build-server {name} {ip} {private_ip}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up a Forge server with the blacksmith configuration.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $forge = new Forge(config('blacksmith.forge_token'));
        $config = json_decode(file_get_contents(base_path('.blacksmith/config.json')), true);

        // Handle Server Rebuild
        $server = $forge->setTimeout(120)->createServer([
            "ubuntu_version" => $config['ubuntu_version'],
            "provider" => ServerProviders::CUSTOM,
            "name" => $this->argument('name'),
            "type" => ServerTypes::APP,
            "php_version"=> $config['php_version'],
            "ip_address" => $this->argument('ip'),
            "private_ip_address" => $this->argument('private_ip'),
        ]);

        $contents = <<<EOT
            id: {$server->id}
            sudo: {$server->sudoPassword}
            provision command: {$server->provisionCommand}
        EOT;

        file_put_contents(base_path('.blacksmith/server-'.$server->id.'.txt'), $contents);
        $configContents = json_decode(file_get_contents(base_path('.blacksmith/config.json')));
        $configContents['server'] = $server->id;
        file_put_contents(base_path('.blacksmith/config.json'), json_encode($configContents, JSON_PRETTY_PRINT));

        $this->info('Server Creation initialized.');
        $this->info('Please wait approximately 10 minutes before building the site.');

        return 0;
    }
}
