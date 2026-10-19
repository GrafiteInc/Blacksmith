<?php

namespace Grafite\Blacksmith\Commands;

use Grafite\Blacksmith\Actions\ConfigureBalancer;
use Grafite\Blacksmith\Actions\ProvisionServer;
use Grafite\Blacksmith\Actions\SyncSite;
use RuntimeException;

class Balancer extends BlacksmithCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:balancer
        {name : The name of the load balancer instance}
        {--site= : The site to balance}
        {--method=round_robin : round_robin, least_conn or ip_hash}
        {--profile=balancer : The server profile in .blacksmith/servers}
        {--ip= : Use an existing server at this IP instead of creating one}
        {--private-ip= : The private IP of the existing server}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a load balancer in front of a site, ready to scale it across servers.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(ProvisionServer $provision, SyncSite $site)
    {
        $name = $this->argument('name');
        $method = $this->option('method');

        try {
            $domain = $this->siteOption();
            $pool = $this->state()->pool($domain);

            if ($pool && $pool['balancer']) {
                throw new RuntimeException("{$domain} is already balanced by {$pool['balancer']}.");
            }

            if (! in_array($method, ConfigureBalancer::METHODS, true)) {
                throw new RuntimeException('The method must be one of: '.implode(', ', ConfigureBalancer::METHODS));
            }

            $primary = $pool['primary'] ?? $this->state()->primaryInstanceFor($domain);

            if (! $primary) {
                throw new RuntimeException("{$domain} is not on any instance yet, run blacksmith:instance first.");
            }

            $this->ensureProfile($this->option('profile'));

            $provision->reportUsing($this->reporter())
                ->handle($name, $this->option('profile'), $this->option('ip'), $this->option('private-ip'));

            $this->state()->putPool($domain, [
                'balancer' => $name,
                'primary' => $primary,
                'nodes' => $pool['nodes'] ?? [$primary],
                'method' => $method,
            ]);

            $site->handle($name, $domain, $this->plan(), [], 'balancer');
        } catch (RuntimeException $exception) {
            return $this->failWith($exception);
        }

        $instance = $this->state()->instance($name);

        $this->info("{$name} is balancing {$domain} at {$instance['ip_address']}.");
        $this->line('Next:');
        $this->line("  1. Point {$domain}'s DNS at {$instance['ip_address']}");
        $this->line("  2. php artisan blacksmith:update-server {$name} --only=ssl");
        $this->line("  3. php artisan blacksmith:scale {$domain} --to=2");

        return self::SUCCESS;
    }
}
