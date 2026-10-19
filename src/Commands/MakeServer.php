<?php

namespace Grafite\Blacksmith\Commands;

use Grafite\Blacksmith\Actions\ProvisionServer;
use Grafite\Blacksmith\Actions\SyncServer;
use RuntimeException;

class MakeServer extends BlacksmithCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:make-server
        {instance : The name of the new instance}
        {--profile=app : The server profile in .blacksmith/servers}
        {--ip= : Use an existing server at this IP instead of creating one}
        {--private-ip= : The private IP of the existing server}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a server, connect it to Forge as a custom server and provision it.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(ProvisionServer $provision, SyncServer $server)
    {
        $instance = $this->argument('instance');

        try {
            $this->ensureProfile($this->option('profile'));

            $provision->reportUsing($this->reporter())
                ->handle($instance, $this->option('profile'), $this->option('ip'), $this->option('private-ip'));

            $server->handle($instance, $this->plan());
        } catch (RuntimeException $exception) {
            return $this->failWith($exception);
        }

        $this->info("Instance {$instance} is ready, add sites with: php artisan blacksmith:update-server {$instance} --site=example.com");

        return self::SUCCESS;
    }
}
