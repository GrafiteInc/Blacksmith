<?php

namespace Grafite\Blacksmith\Commands;

use Laravel\Forge\Forge;
use Illuminate\Support\Str;
use Illuminate\Console\Command;
use Laravel\Forge\Resources\ServerTypes;
use Laravel\Forge\Resources\ServerProviders;

class CloneServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:clone-server {id} {ip} {--private_ip=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up a Forge server and create a blacksmith configuration file based on it.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $forge = new Forge(config('blacksmith.forge_token'));

        // Get the config
        $config = json_decode(file_get_contents(base_path('.blacksmith/'.$this->argument('id').'/config.json'), true));

        // Handle Server Build
        $server = $forge->setTimeout(120)->createServer([
            "ubuntu_version" => $config['server']['ubuntu_version'] ?? '24.04',
            "provider" => ServerProviders::CUSTOM,
            "name" => $this->option('name') ?? 'server-'.Str::random(10),
            "type" => ServerTypes::APP,
            "php_version"=> $config['server']['ubuntu_version'] ?? 'php83',
            "php_cli_version"=> $config['server']['ubuntu_version'] ?? 'php83',
            "max_upload_size"=> '5',
            "max_execution_time"=> '30',
            "ip_address" => $this->argument('ip'),
            "private_ip_address" => $this->option('private_ip'),
        ]);

        if (! is_dir(base_path('.blacksmith'))) {
            mkdir(base_path('.blacksmith'));
        }

        mkdir(base_path('.blacksmith/'.$server->id));
        touch(base_path('.blacksmith/'.$server->id.'/config.json'));

        file_put_contents(base_path('.blacksmith/'.$server->id.'/config.json'), json_encode([
            "server" => [
                "id" => $server->id,
                "name" => $server->name,
                "ip_address" => $this->argument('ip'),
                "private_ip_address" => $this->option('private_ip') ?? null,
                "php_version" => $server->phpVersion,
                "opcache_enabled" => true,
                "ubuntu_version" => $server->ubuntuVersion,
            ],
            "sites" => [],
        ], JSON_PRETTY_PRINT));

        $contents = <<<EOT
            id: {$server->id}
            sudo: {$server->sudoPassword}
            provision command: {$server->provisionCommand}
        EOT;

        file_put_contents(base_path('.blacksmith/'.$server->id.'/provision.txt'), $contents);

        $this->info('Server Creation initialized.');
        $this->info('Please run the provision command on the server.');
        $this->info('Please wait approximately 10 minutes before building the site.');
        $this->info('You can check the progress here: https://forge.laravel.com/servers/'.$server->id);

        return 0;
    }
}
