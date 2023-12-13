<?php

namespace Grafite\Blacksmith\Commands;

use Laravel\Forge\Forge;
use Illuminate\Support\Str;
use Illuminate\Console\Command;

class UpdateServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:update-server {--server=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the configuration of a Forge server.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $forge = new Forge(config('blacksmith.forge_token'));
        $serverIds = [$this->option('server')];

        if (! $this->option('server')) {
            $serverIds = collect(glob(base_path('.blacksmith') . '/*' , GLOB_ONLYDIR))->map(function ($server) {
                return Str::of($server)->replace(base_path('.blacksmith/'), '')->toString();
            })->toArray();
        }

        foreach ($serverIds as $serverId) {
            $config = json_decode(file_get_contents(base_path('.blacksmith/'.$serverId.'/config.json')), true);
            $config = $config['server'];

            $server = $forge->server($serverId);

            if ($config['opcache_enabled']) {
                $server->enableOPCache();
            } else {
                $server->disableOPCache();
            }

            if (! collect($server->phpVersions)->pluck('version')->contains($config['php_version'])) {
                $server->installPHP($config['php_version']);
            }

            $forge->setTimeout(120)->updateServer($serverId, [
                "name" => $config['name'],
                "php_version" => $config['php_version'],
                "php_cli_version" => $config['php_cli_version'],
                'max_upload_size' => $config['max_upload_size'],
                'max_execution_time' => $config['max_execution_time'],
                'ip_address' => $config['ip_address'],
                'private_ip_address' => $config['private_ip_address'],
            ], true);

            $this->info('Server updated');
        }

        return 0;
    }
}
