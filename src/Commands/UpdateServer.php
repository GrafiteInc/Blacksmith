<?php

namespace Grafite\Blacksmith\Commands;

use Grafite\Blacksmith\Actions\SyncServer;
use Grafite\Blacksmith\Actions\SyncSite;
use Laravel\Forge\Forge;
use RuntimeException;

class UpdateServer extends BlacksmithCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:update-server
        {instance? : The instance to update, all instances when omitted}
        {--site=* : Only these sites, a site missing from the server is created}
        {--only= : Comma separated sections: server,site,repository,env,deploy,scheduler,workers,ssl,quick_deploy,redirects,security,balancing}
        {--deploy : Trigger a deployment after updating}
        {--dry-run : Show what would change without changing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Push the server profile and site blueprints to Forge.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(Forge $forge, SyncServer $server, SyncSite $site)
    {
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $unknown = array_diff($only, array_merge(['server'], SyncSite::SECTIONS));

        if ($unknown) {
            $this->error('Unknown sections: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        $plan = $this->plan((bool) $this->option('dry-run'));
        $siteSections = array_values(array_diff($only, ['server']));

        try {
            foreach ($this->resolveInstances($this->argument('instance')) as $instance) {
                if (empty($only) || in_array('server', $only, true)) {
                    $server->handle($instance, $plan);
                }

                if (! empty($only) && empty($siteSections)) {
                    continue;
                }

                $sites = $this->resolveSites($instance, $this->option('site'));

                if (empty($sites)) {
                    $this->warn("{$instance}: No sites, pass --site to add one.");
                }

                foreach ($sites as $domain) {
                    $siteId = $site->handle($instance, $domain, $plan, $siteSections);

                    if ($this->option('deploy') && $this->state()->roleFor($instance, $domain) !== 'balancer') {
                        $serverId = $this->state()->instance($instance)['server_id'];

                        $plan->step("{$instance} {$domain}: Deploy", fn () => $forge->deploySite($serverId, $siteId, false));
                    }
                }
            }
        } catch (RuntimeException $exception) {
            return $this->failWith($exception);
        }

        if (empty($plan->steps())) {
            $this->info('Everything matches the blueprints.');
        } elseif ($plan->dryRun) {
            $this->info(count($plan->steps()).' change(s) planned, run without --dry-run to apply.');
        } else {
            $this->info(count($plan->steps()).' change(s) applied.');
        }

        return self::SUCCESS;
    }
}
