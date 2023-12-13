<?php

namespace Grafite\Blacksmith\Commands;

use Laravel\Forge\Forge;
use Illuminate\Support\Str;
use Illuminate\Console\Command;

class BuildSite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:build-site {--server=} {--site=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Set up a Forge site with the blacksmith configuration.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $forge = new Forge(config('blacksmith.forge_token'));
        $serverId = $this->option('server');

        if (! $this->option('server')) {
            $serverId = collect(glob(base_path('.blacksmith') . '/*' , GLOB_ONLYDIR))->map(function ($server) {
                return Str::of($server)->replace(base_path('.blacksmith/'), '')->toString();
            })->first();
        }

        $config = json_decode(file_get_contents(base_path('.blacksmith/'.$serverId.'/config.json')), true);

        if (empty($config['sites'])) {
            $this->info('No sites to build.');

            if ($this->confirm('Would you like to add an existing site config?')) {
                $domain = $this->ask('What is the domain of the site?');

                $config['sites'][] = [
                    "php_version" => "php82",
                    "domain" => "{$domain}",
                    "directory" => "/public",
                    "lets_encrypt" => true,
                    "scheduler_enabled" => true,
                    "ssl_domains" => ["{$domain}", "www.{$domain}"],
                    "enable_quick_deploy" => false,
                    "repository" => [
                        "provider" => "github",
                        "repository" => "username/repository",
                        "branch" => "main",
                        "composer" => true
                    ],
                    "environment_variables_file" => "{$domain}.env",
                    "deployment_file" => "{$domain}.deploy",
                    "workers" => [
                        "default"  => [
                            "connection"  => "database",
                            "queue"  => "default",
                            "tries" => 1,
                            "timeout" => 0,
                            "processes" => 1,
                            "stopwaitsecs" => 600,
                            "sleep" => 10,
                            "force" => false,
                            "daemon" => true,
                            "php_version" => "php"
                        ]
                    ],
                    "security" => [
                        "name" => "",
                        "path" => "",
                        "credentials" => [
                            "username" => "",
                            "password" => ""
                        ]
                    ],
                    "redirects"  => [
                        [
                            "from"  => "",
                            "to" => "",
                            "type" => "permanent"
                        ]
                    ]
                ];
            }

            file_put_contents(base_path('.blacksmith/'.$serverId.'/'.$domain.'.env'), '');
            file_put_contents(base_path('.blacksmith/'.$serverId.'/'.$domain.'.deploy'), '');
            file_put_contents(base_path('.blacksmith/'.$serverId.'/config.json'), json_encode($config, JSON_PRETTY_PRINT));

            $this->info('Site added to config.json');
            $this->info('Site env and deploy files created.');

            $this->info('Please configure this to match your desired outcome, then run blacksmith:build-site again.');

            return 0;
        }

        // Remove default site
        foreach ($forge->sites($serverId) as $site) {
            if ($site->name === 'default') {
                $forge->deleteSite($serverId, $site->id);
            }
        }

        if ($this->option('site')) {
            $config['sites'] = collect($config['sites'])->filter(function ($site) {
                return $site['id'] == $this->option('site');
            })->toArray();
        };

        foreach ($config['sites'] as $key => $siteConfig) {
            $basePath = base_path('.blacksmith/'.$serverId.'/');

            if (isset($siteConfig['id']) && collect($forge->sites($serverId))->pluck('id')->contains($siteConfig['id'])) {
                $forge->deleteSite($serverId, $siteConfig['id']);
                unset($siteConfig['id']);

                sleep(15);
            }

            // create new site
            $site = $forge->setTimeout(120)->createSite($serverId, [
                "domain" => $siteConfig['domain'],
                "project_type" => "php",
                "directory" => $siteConfig['directory'] ?? "/public",
                "isolated" => true,
                "php_version" => $siteConfig['php_version'],
            ], true);

            $this->info('Site ID: '.$site->id.' created.');

            $siteConfig['id'] = $site->id;
            $config['sites'][$key] = $siteConfig;

            file_put_contents(base_path('.blacksmith/'.$serverId.'/config.json'), json_encode($config, JSON_PRETTY_PRINT));

            $siteId = $site->id;

            // Handle Repository
            if ($siteConfig['repository']) {
                $forge->installGitRepositoryOnSite($serverId, $siteId, $siteConfig['repository'], true);
                $this->info('Site Repository done.');
            }

            // handling environment files
            $forge->updateSiteEnvironmentFile($serverId, $siteId, file_get_contents($basePath.$siteConfig['environment_variables_file']));
            $this->info('Site environment done.');

            // handling cron jobs
            if ($siteConfig['scheduler_enabled']) {
                $forge->createJob($serverId, [
                    "command" => "{$siteConfig['php_version']} /home/forge/{$siteConfig['domain']}/artisan schedule:run",
                    "frequency" => "custom",
                    "user" => "forge",
                    "minute" => "*",
                    "hour" => "*",
                    "day" => "*",
                    "month" => "*",
                    "weekday" => "*"
                ], false);

                $this->info('Cron jobs done.');
            }

            // handling deployment script
            $forge->updateSiteDeploymentScript($serverId, $siteId, file_get_contents($basePath.$siteConfig['deployment_file']));
            $this->info('Deployment script done.');

            // Handling workers
            if ($siteConfig['workers']) {
                foreach ($siteConfig['workers'] as $options) {
                    $forge->createWorker($serverId, $siteId, $options, false);
                }
                $this->info('Workers done.');
            }

            // Handle SSL
            if ($siteConfig['lets_encrypt']) {
                $forge->obtainLetsEncryptCertificate($serverId, $siteId, [
                    'domains' => $siteConfig['domains'],
                ], false);
                $this->info('SSL done.');
            }

            // Handle Quick Deploy
            if ($siteConfig['enable_quick_deploy']) {
                $forge->enableQuickDeploy($serverId, $siteId);
                $this->info('Quick deploy done.');
            }

            // Handle Security
            if (isset($siteConfig['security'])) {
                $forge->createSecurityRule($serverId, $siteId, $siteConfig['security']);
                $this->info('Security done.');
            }

            // Handle Redirects
            if (isset($siteConfig['security'])) {
                foreach ($siteConfig['redirects'] as $redirect) {
                    $forge->createRedirectRule($serverId, $siteId, $redirect, false);
                }

                $this->info('Redirects done.');
            }

            $this->info('Site build complete.');
        }

        return 0;
    }
}
