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
    protected $signature = 'blacksmith:localize';

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
        $config = json_decode(file_get_contents(base_path('.blacksmith/config.json')), true);

        // handling environment files
        $environment = $forge->siteEnvironmentFile($config['server'], $config['site']);
        file_put_contents(base_path('.blacksmith/.env.production'), $environment);

        // Handling deployment script
        $deploy = $forge->siteDeploymentScript($config['server'], $config['site']);
        file_put_contents(base_path('.blacksmith/.deploy.production'), $deploy);

        $this->info('Laravel Forge data localized.');

        return 0;
    }
}
