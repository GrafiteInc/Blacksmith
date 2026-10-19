<?php

namespace Grafite\Blacksmith\Commands;

use Grafite\Blacksmith\Actions\BuildInstance;
use RuntimeException;

class Instance extends BlacksmithCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:instance
        {name : The name of the new instance}
        {--site=* : The site blueprints to put on it, all of them when omitted}
        {--profile=app : The server profile in .blacksmith/servers}
        {--ip= : Use an existing server at this IP instead of creating one}
        {--private-ip= : The private IP of the existing server}
        {--no-deploy : Skip the first deployment}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new server with copies of your sites on it and deploy them.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(BuildInstance $build)
    {
        $name = $this->argument('name');
        $sites = $this->option('site') ?: $this->blueprints()->sites();

        try {
            if (empty($sites)) {
                throw new RuntimeException('There are no site blueprints, run blacksmith:localize first.');
            }

            foreach ($sites as $domain) {
                $this->blueprints()->site($domain);
            }

            $this->ensureProfile($this->option('profile'));

            $build->reportUsing($this->reporter())->handle(
                $name,
                $this->option('profile'),
                $sites,
                $this->plan(),
                $this->option('ip'),
                $this->option('private-ip'),
                null,
                ! $this->option('no-deploy'),
            );
        } catch (RuntimeException $exception) {
            return $this->failWith($exception);
        }

        $instance = $this->state()->instance($name);

        $this->info("Instance {$name} is live at {$instance['ip_address']} with: ".implode(', ', $sites));
        $this->line('Point DNS at it when you are ready, the env can be customized per instance in .blacksmith/sites/{domain}/.env.'.$name);

        return self::SUCCESS;
    }
}
