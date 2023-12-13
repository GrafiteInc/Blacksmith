<?php

namespace Grafite\Blacksmith\Commands;

use Laravel\Forge\Forge;
use Illuminate\Console\Command;

class BuildSite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:build-site {environment} {--server=}';

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
        $config = json_decode(file_get_contents(base_path('.blacksmith/config.json')), true);

        $serverId = $this->option('server') ?? $config['server'];

        $server = $forge->server($serverId);
        $server->enableOPCache();

        // Remove default site
        foreach ($forge->sites($serverId) as $site) {
            $forge->deleteSite($serverId, $site->id);
        }

        // create new site
        $site = $forge->setTimeout(120)->createSite($serverId, [
            "domain" => $config['domain'],
            "project_type" => "php",
            "directory" => "/public",
            "isolated" => true,
            "php_version" => $config['php_version'],
        ], true);

        $this->info('Site ID: '.$site->id);

        $configContents = json_decode(file_get_contents(base_path('.blacksmith/config.json')));
        $configContents['site'] = $site->id;
        file_put_contents(base_path('.blacksmith/config.json'), json_encode($configContents, JSON_PRETTY_PRINT));

        $siteId = $site->id;

        // Handle Repository
        if ($config['repository']) {
            $forge->installGitRepositoryOnSite($serverId, $siteId, $config['repository'], true);
            $this->info('Site Repository done.');
        }

        // handling environment files
        $forge->updateSiteEnvironmentFile($serverId, $siteId, file_get_contents(base_path('.blacksmith/'.$config['environment_variables_file'].'.'.$this->argument('environment'))));
        $this->info('Site environment done.');

        // handling cron jobs
        if ($config['scheduler_enabled']) {
            $forge->createJob($serverId, [
                "command" => "php /home/forge/{$config['domain']}/artisan schedule:run",
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
        $forge->updateSiteDeploymentScript($serverId, $siteId, file_get_contents(base_path('.blacksmith/'.$config['deployment_file'].'.'.$this->argument('environment'))));
        $this->info('Deployment script done.');

        // Handling workers
        if ($config['workers']) {
            foreach ($config['workers'] as $options) {
                $forge->createWorker($serverId, $siteId, $options, false);
            }
            $this->info('Workers done.');
        }

        // Handle SSL
        if ($config['lets_encrypt']) {
            $forge->obtainLetsEncryptCertificate($serverId, $siteId, [
                'domains' => $config['domains'],
            ], false);
            $this->info('SSL done.');
        }

        // Handle Quick Deploy
        if ($config['enable_quick_deploy']) {
            $forge->enableQuickDeploy($serverId, $siteId);
            $this->info('Quick deploy done.');
        }

        // Handle Security
        if ($config['security']) {
            $forge->createSecurityRule($serverId, $siteId, $config['security']);
            $this->info('Security done.');
        }

        // Handle Redirects
        if ($config['redirects']) {
            foreach ($config['redirects'] as $redirect) {
                $forge->createRedirectRule($serverId, $siteId, $redirect, false);
            }

            $this->info('Redirects done.');
        }

        return 0;
    }
}
