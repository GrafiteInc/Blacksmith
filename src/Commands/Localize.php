<?php

namespace Grafite\Blacksmith\Commands;

use Laravel\Forge\Forge;
use Illuminate\Console\Command;

class Localize extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:localize {--server=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up a blacksmith configuration from an existing Forge server.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $forge = new Forge(config('blacksmith.forge_token'));
        $basePath = base_path('.blacksmith');

        if (file_exists($basePath.'/config.json')) {
            $config = json_decode(file_get_contents($basePath.'/config.json'), true);
        }

        if ($this->option('server') && ! file_exists(base_path('.blacksmith/'.$this->option('server').'/config.json'))) {
            $this->info('Server does not exist in your local blacksmith configuration.');
            $this->info('php artisan blacksmith:setup --server='.$this->option('server'));

            return 0;
        }

        if ($this->option('server') && file_exists(base_path('.blacksmith/'.$this->option('server').'/config.json'))) {
            $config = json_decode(file_get_contents(base_path('.blacksmith/'.$this->option('server').'/config.json')), true);
            $basePath = base_path('.blacksmith/'.$this->option('server'));
        }

        throw_if(is_null($config), new \Exception('No configuration found. Run php artisan blacksmith:setup'));

        $serverId = $config['server']['id'];

        $server = $forge->server($serverId);

        $config['server']['id'] = $server->id;
        $config['server']['name'] = $server->name;
        $config['server']['ubuntu_version'] = $server->ubuntuVersion;
        $config['server']['ip_address'] = $server->ipAddress;
        $config['server']['private_ip_address'] = $server->privateIpAddress;
        $config['server']['opcache_enabled'] = ($server->opcacheStatus === 'enabled') ? true : false;

        file_put_contents($basePath.'/config.json', json_encode($config, JSON_PRETTY_PRINT));

        $sites = [];

        foreach ($forge->sites($serverId) as $key => $site) {
            $sites[$key]['id'] = $site->id;
            $sites[$key]['domain'] = $site->name;
            $sites[$key]['php_version'] = $site->phpVersion;
            $sites[$key]['directory'] = $site->directory;
            $sites[$key]['scheduler_enabled'] = false;
            $sites[$key]['lets_encrypt'] = $site->isSecured;
            $sites[$key]['ssl_domains'] = [
                $site->name,
                'www.'.$site->name,
            ];
            $sites[$key]['enable_quick_deploy'] = $site->quickDeploy;

            $sites[$key]['repository'] = [
                'repository' => $site->repository,
                'provider' => $site->repositoryProvider,
                'branch' => $site->repositoryBranch,
                'composer' => false,
            ];


            $sites[$key]['environment_variables_file'] = $site->name.'.env';
            $sites[$key]['deployment_file'] = $site->name.'.deploy';

            $sites[$key]['workers'] = null;
            $sites[$key]['security'] = null;
            $sites[$key]['redirects'] = null;

            // handling environment files
            $environment = $forge->siteEnvironmentFile($config['server']['id'], $site->id);
            file_put_contents($basePath.'/'.$site->name.'.env', $environment);

            // Handling deployment script
            $deploy = $forge->siteDeploymentScript($config['server']['id'], $site->id);
            file_put_contents($basePath.'/'.$site->name.'.deploy', $deploy);

            $this->info('Laravel Forge data localized for site: '.$site->name);
        }

        $config['sites'] = $sites;

        file_put_contents($basePath.'/config.json', json_encode($config, JSON_PRETTY_PRINT));

        return 0;
    }
}
