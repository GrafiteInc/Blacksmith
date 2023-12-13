<?php

namespace Grafite\Blacksmith\Commands;

use Illuminate\Support\Str;
use Illuminate\Console\Command;

class Setup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:setup {--domain=} {--server=} {--site=}';

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
        $basePath = base_path('.blacksmith');

        if (! is_dir($basePath)) {
            mkdir($basePath);
        }

        if ($this->option('server')) {
            mkdir($basePath.'/'.$this->option('server'));
            $basePath = $basePath.'/'.$this->option('server');
        }

        // Create the .blacksmith/config.json file
        touch($basePath.'/config.json');

        $server = $this->option('server') ?? "unknown";
        $site = $this->option('site') ?? "unknown";
        $domain = $this->option('domain') ?? "domain";
        $name = 'server-'.Str::random(10);

        $configContents = <<<EOT
            {
                "server": {
                    "id": "$server",
                    "name": "$name",
                    "ip_address": "",
                    "private_ip_address": "",
                    "php_version": "php82",
                    "php_cli_version": "php82",
                    "max_upload_size": 5,
                    "max_execution_time": 30,
                    "opcache_enabled": true,
                    "ubuntu_version": "22.04"
                },
                "sites": [
                    {
                        "id": "{$site}",
                        "php_version": "php82",
                        "domain": "{$domain}",
                        "directory": "/public",
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
                        "environment_variables_file": "{$domain}.env",
                        "deployment_file": "{$domain}.deploy",
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
                ]
            }
        EOT;

        // Update the .blacksmith/config.json file
        file_put_contents($basePath.'/config.json', $configContents);

        // Add .blacksmith to .gitignore
        $gitignoreContents = file_get_contents(base_path('.gitignore'));
        $gitignoreContents .= "\n.blacksmith";
        file_put_contents(base_path('.gitignore'), $gitignoreContents);

        if ($domain !== 'domain') {
            // Create the environment file
            touch($basePath.'/'.$domain.'.env');

            // Create the deployment script
            touch($basePath.'/'.$domain.'.deploy');
        }

        $this->info('Blacksmith configuration created.');

        return 0;
    }
}
