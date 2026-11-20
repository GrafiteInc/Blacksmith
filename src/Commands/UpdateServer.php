<?php

namespace Grafite\Blacksmith\Commands;

use Grafite\Blacksmith\Actions\RollingDeploy;
use Grafite\Blacksmith\Actions\SyncServer;
use Grafite\Blacksmith\Actions\SyncSite;
use Grafite\Blacksmith\Forge\ForgeGateway;
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
        {--rolling : With --deploy, deploy load balanced sites one node at a time, taking each out of the balancer while it deploys}
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
    public function handle(ForgeGateway $forge, SyncServer $server, SyncSite $site, RollingDeploy $rollingDeploy)
    {
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $unknown = array_diff($only, array_merge(['server'], SyncSite::SECTIONS));

        if ($unknown) {
            $this->error('Unknown sections: '.implode(', ', $unknown));

            return self::FAILURE;
        }

        if ($this->option('rolling') && ! $this->option('deploy')) {
            $this->error('--rolling only changes how --deploy deploys, pass both.');

            return self::FAILURE;
        }

        // Pooled sites deploy once every instance is updated, one node at a time.
        $rolling = [];

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

                    if ($this->option('rolling') && $this->isBalanced($instance, $domain)) {
                        $rolling[$domain] = true;

                        continue;
                    }

                    if ($this->option('deploy') && $this->state()->roleFor($instance, $domain) !== 'balancer') {
                        $serverId = $this->state()->instance($instance)['server_id'];

                        $plan->step("{$instance} {$domain}: Deploy", fn () => $forge->deploySite($serverId, $siteId));
                    }
                }
            }

            foreach (array_keys($rolling) as $domain) {
                $rollingDeploy->handle($domain, $plan);
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

    /**
     * Whether the site sits behind a balancer this instance is part of, as a node or as the balancer.
     */
    protected function isBalanced(string $instance, string $domain): bool
    {
        $pool = $this->state()->pool($domain);

        return $pool && $pool['balancer'] && ($pool['balancer'] === $instance || in_array($instance, $pool['nodes'], true));
    }
}
