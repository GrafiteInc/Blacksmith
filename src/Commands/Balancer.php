<?php

namespace Grafite\Blacksmith\Commands;

use Grafite\Blacksmith\Actions\ConfigureBalancer;
use Grafite\Blacksmith\Actions\PointDns;
use Grafite\Blacksmith\Actions\ProvisionServer;
use Grafite\Blacksmith\Actions\SyncSite;
use Grafite\Blacksmith\Support\Defaults;
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
        {--method= : round_robin, least_conn or ip_hash, defaults to blacksmith.defaults.balancer.method}
        {--profile=balancer : The server profile in .blacksmith/servers}
        {--ip= : Use an existing server at this IP instead of creating one}
        {--private-ip= : The private IP of the existing server}
        {--no-dns : Leave DNS alone, point it at the balancer yourself}';

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
    public function handle(ProvisionServer $provision, SyncSite $site, PointDns $dns)
    {
        $name = $this->argument('name');
        $method = $this->option('method') ?: Defaults::get('balancer.method', 'round_robin');
        $pointDns = false;

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

            $plan = $this->plan();
            $pointDns = ! $this->option('no-dns') && $dns->manages($domain);

            if ($pointDns) {
                $site->handle($name, $domain, $plan, array_values(array_diff(SyncSite::SECTIONS, ['ssl'])), 'balancer');
                $dns->handle($domain, $name, $plan);
                $site->handle($name, $domain, $plan, ['ssl'], 'balancer');
            } else {
                $site->handle($name, $domain, $plan, [], 'balancer');
            }
        } catch (RuntimeException $exception) {
            return $this->failWith($exception);
        } finally {
            $this->printAccessReport();
        }

        $instance = $this->state()->instance($name);

        $this->info("{$name} is balancing {$domain} at {$instance['ip_address']}.");
        $this->line('Next:');

        if (! $pointDns) {
            $this->line("  - Point {$domain}'s DNS at {$instance['ip_address']}");
            $this->line("  - php artisan blacksmith:update-server {$name} --only=ssl");
        }

        $this->line("  - php artisan blacksmith:scale {$domain} --to=2");

        return self::SUCCESS;
    }
}
