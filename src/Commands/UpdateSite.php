<?php

namespace Grafite\Blacksmith\Commands;

use Laravel\Forge\Forge;
use Illuminate\Console\Command;

class UpdateSite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:update-site {environment}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the configuration of the Forge server.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $forge = new Forge(config('blacksmith.forge_token'));
        $config = json_decode(file_get_contents(base_path('.blacksmith/config.json')), true);

        $serverId = $config['server'];
        $siteId = $config['site'];

        $server = $forge->server($serverId);
        $server->enableOPCache();

        // create new site
        $forge->setTimeout(120)->updateSite($serverId, $siteId, [
            "domain" => $config['domain'],
            "php_version" => $config['php_version'],
        ], true);
        $this->info('Site updated');

        // Handle Repository
        if (isset($config['repository'])) {
            $forge->updateSiteGitRepository($serverId, $siteId, $config['repository'], true);
            $this->info('Site Repository updated.');
        }

        // handling environment files
        $forge->updateSiteEnvironmentFile($serverId, $siteId, file_get_contents(base_path('.blacksmith/'.$config['environment_variables_file'].'.'.$this->argument('environment'))));
        $this->info('Site environment done.');

        // handling cron jobs
        // if ($config['scheduler_enabled']) {
        //     $forge->createJob($serverId, [
        //         "command" => "php /home/forge/{$config['domain']}/artisan schedule:run",
        //         "frequency" => "custom",
        //         "user" => "forge",
        //         "minute" => "*",
        //         "hour" => "*",
        //         "day" => "*",
        //         "month" => "*",
        //         "weekday" => "*"
        //     ], false);
        //     $this->info('Cron jobs done.');
        // }

        // handling deployment script
        $forge->updateSiteDeploymentScript($serverId, $siteId, file_get_contents(base_path('.blacksmith/'.$config['deployment_file'].'.'.$this->argument('environment'))));
        $this->info('Deployment script done.');

        // Handling workers
        if (isset($config['workers'])) {
            $this->info('Removing old workers.');
            foreach ($forge->workers($serverId, $siteId) as $worker) {
                $forge->deleteWorker($serverId, $siteId, $worker->id);
            }

            foreach ($config['workers'] as $options) {
                $forge->createWorker($serverId, $siteId, $options, false);
            }

            $this->info('Workers updated.');
        }

        // Handle SSL
        // if ($config['lets_encrypt']) {
        //     $forge->obtainLetsEncryptCertificate($serverId, $siteId, [
        //         'domains' => $config['domains'],
        //     ], false);
        //     $this->info('SSL done.');
        // }

        // Handle Quick Deploy
        if ($config['enable_quick_deploy']) {
            $forge->enableQuickDeploy($serverId, $siteId);
            $this->info('Quick deploy done.');
        }

        // Handle Security
        if (isset($config['security'])) {
            foreach ($forge->securityRules($serverId, $siteId) as $rule) {
                $forge->deleteSecurityRule($serverId, $siteId, $rule->id);
            }

            $forge->createSecurityRule($serverId, $siteId, $config['security']);
            $this->info('Security updated.');
        }

        // Handle Redirects
        if (isset($config['redirects'])) {
            foreach ($forge->redirectRules($serverId, $siteId) as $rule) {
                $forge->deleteRedirectRule($serverId, $siteId, $rule->id);
            }

            foreach ($config['redirects'] as $redirect) {
                $forge->createRedirectRule($serverId, $siteId, $redirect, false);
            }

            $this->info('Redirects updated.');
        }

        return 0;
    }
}
