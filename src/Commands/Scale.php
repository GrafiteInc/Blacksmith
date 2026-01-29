<?php

namespace Grafite\Blacksmith\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Laravel\Forge\Forge;

class Scale extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:scale {--server=} {--site=} {--scope=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deploy a Forge site.';

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
            $serverIds = collect(glob(base_path('.blacksmith').'/*', GLOB_ONLYDIR))->map(function ($server) {
                return Str::of($server)->replace(base_path('.blacksmith/'), '')->toString();
            })->toArray();
        }

        // get servers that are not load balancers
        // clone one
        // build the site from the cloned server on the new server
        // update the load balancer to point to the new server

        foreach ($serverIds as $serverId) {
            $basePath = base_path('.blacksmith/'.$serverId.'/');
            $config = json_decode(file_get_contents($basePath.'config.json'), true);
            $siteConfigs = $config['sites'];

            if ($this->option('site')) {
                $config = collect($config['sites'])->where('id', $this->option('site'))->first();
                $siteConfigs = ['sites' => $config];
            }

            foreach ($siteConfigs as $config) {
                $siteId = $config['id'];

                $forge->post("servers/$serverId/sites/$siteId/deployment/deploy");

                $this->info('Site deployment triggered.');
            }
        }

        return 0;
    }
}
