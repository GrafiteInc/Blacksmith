<?php

namespace Grafite\Blacksmith\Commands;

use Illuminate\Console\Command;

class Setup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:setup {domain} {--server=} {--site=} {--project=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a blacksmith configuration.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        // Create the .blacksmith directory
        mkdir(base_path('.blacksmith'));

        // Create the .blacksmith/config.json file
        touch(base_path('.blacksmith/config.json'));

        $server = $this->option('server') ?? null;
        $site = $this->option('site') ?? null;
        $domain = $this->argument('domain');
        $project = $this->option('project');

        $configContents = <<<EOT
            {
                "project": "{$project}",
                "server": {$server},
                "site": {$site},
                "php_version": "php82",
                "ubuntu_version": "22.04",
                "domain": "{$domain}",
                "lets_encrypt": true,
                "scheduler_enabled": true,
                "ssl_domains": ["{$domain}", "www.{$domain}"],
                "enable_quick_deploy": false,
                "repository": {
                    "provider": "github",
                    "repository": "username/repository",
                    "branch": "main",
                    "composer": true
                },
                "environment_variables_file": ".env",
                "deployment_file": ".deploy",
                "workers": {
                    "default" : {
                        "connection" : "database",
                        "queue" : "default",
                        "tries": 1,
                        "timeout": 0,
                        "processes": 1,
                        "stopwaitsecs": 600,
                        "sleep": 10,
                        "force": false,
                        "daemon": true,
                        "php_version": "php"
                    }
                },
                "security": {
                    "name": "",
                    "path": "",
                    "credentials": {
                        "username" : "",
                        "password" : ""
                    }
                },
                "redirects" : [
                    {
                        "from" : "",
                        "to": "",
                        "type": "permanent"
                    }
                ]
            }
        EOT;

        // Update the .blacksmith/config.json file
        file_put_contents(base_path('.blacksmith/config.json'), $configContents);

        // Add .blacksmith to .gitignore
        $gitignoreContents = file_get_contents(base_path('.gitignore'));
        $gitignoreContents .= "\n.blacksmith";
        file_put_contents(base_path('.gitignore'), $gitignoreContents);

        // Create the production environment file
        touch(base_path('.blacksmith/.env.production'));

        // Create the deployment script
        touch(base_path('.blacksmith/.deploy.production'));

        $this->info('Blacksmith configuration created.');

        return 0;
    }
}
