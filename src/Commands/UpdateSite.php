<?php

namespace Grafite\Blacksmith\Commands;

use Laravel\Forge\Forge;
use Illuminate\Support\Str;
use Illuminate\Console\Command;

class UpdateSite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:update-site {--server=} {--site=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the configuration of a Forge site.';

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
            $basePath = base_path('.blacksmith/'.$serverId.'/');
            $config = json_decode(file_get_contents($basePath.'config.json'), true);
            $siteConfigs = $config['sites'];

            if ($this->option('site')) {
                $config = collect($config['sites'])->where('id', $this->option('site'))->first();
                $siteConfigs = ['sites' => $config];
            }

            foreach ($siteConfigs as $config) {
                $siteId = $config['id'];
                $site = $forge->site($serverId, $siteId);

                if ($config['php_version'] !== $site->phpVersion) {
                    $site->changePHPVersion($config['php_version']);
                }

                // create new site
                $forge->setTimeout(120)->updateSite($serverId, $siteId, [
                    "domain" => $config['domain'],
                    "php_version" => $config['php_version'],
                    "directory" => $config['directory'],
                ], true);

                $this->info($config['domain'].': Site updated');

                // Handle Repository
                if (isset($config['repository']) && ! is_null($config['repository']['repository'])) {
                    $forge->updateSiteGitRepository($serverId, $siteId, $config['repository'], true);
                    $this->info($config['domain'].': Site Repository updated.');
                }

                // handling environment files
                if (! empty(file_get_contents($basePath.$config['environment_variables_file']))) {
                    $forge->updateSiteEnvironmentFile($serverId, $siteId, file_get_contents($basePath.$config['environment_variables_file']));
                    $this->info($config['domain'].': Site environment done.');
                }

                // handling deployment script
                if (! empty(file_get_contents($basePath.$config['deployment_file']))) {
                    $forge->updateSiteDeploymentScript($serverId, $siteId, file_get_contents($basePath.$config['deployment_file']));
                    $this->info($config['domain'].': Deployment script done.');
                }

                // Handling workers
                if (isset($config['workers'])) {
                    $this->info($config['domain'].': Removing old workers.');
                    foreach ($forge->workers($serverId, $siteId) as $worker) {
                        $forge->deleteWorker($serverId, $siteId, $worker->id);
                    }

                    foreach ($config['workers'] as $options) {
                        $forge->createWorker($serverId, $siteId, $options, false);
                    }

                    $this->info($config['domain'].': Workers updated.');
                }

                // Handle Quick Deploy
                if ($config['enable_quick_deploy']) {
                    $forge->enableQuickDeploy($serverId, $siteId);
                    $this->info($config['domain'].': Quick deploy done.');
                }

                // Handle Security
                if (isset($config['security']) && ! is_null($config['security'])) {
                    foreach ($forge->securityRules($serverId, $siteId) as $rule) {
                        $forge->deleteSecurityRule($serverId, $siteId, $rule->id);
                    }

                    foreach ($config['security'] as $security) {
                        $forge->createSecurityRule($serverId, $siteId, $security);
                    }

                    $this->info($config['domain'].': Security updated.');
                }

                // Handle Redirects
                if (isset($config['redirects']) && ! is_null($config['redirects'])) {
                    foreach ($forge->redirectRules($serverId, $siteId) as $rule) {
                        $forge->deleteRedirectRule($serverId, $siteId, $rule->id);
                    }

                    foreach ($config['redirects'] as $redirect) {
                        $forge->createRedirectRule($serverId, $siteId, $redirect, false);
                    }

                    $this->info($config['domain'].': Redirects updated.');
                }
            }
        }

        return 0;
    }
}
