<?php

namespace Grafite\Blacksmith\Commands;

use Grafite\Blacksmith\Actions\PointDns;
use RuntimeException;

class Dns extends BlacksmithCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:dns
        {instance : The instance DNS should point at}
        {--site=* : The sites to point, every public site on the instance when omitted}
        {--force : Also move records that point at servers Blacksmith does not manage}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Point the DNS for an instance\'s sites (domain and aliases) at its server.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(PointDns $dns)
    {
        $instance = $this->argument('instance');

        try {
            if (! PointDns::enabled()) {
                throw new RuntimeException('DNS is not configured, set BLACKSMITH_DNS_PROVIDER=cloudflare and BLACKSMITH_CLOUDFLARE_TOKEN.');
            }

            $record = $this->state()->instance($instance);

            if ($this->state()->isTransient($instance)) {
                throw new RuntimeException("{$instance} is part of a replacement, DNS moves with blacksmith:replace --cutover.");
            }

            $sites = $this->resolveSites($instance, $this->option('site'));

            if (empty($sites)) {
                throw new RuntimeException("{$instance} has no sites.");
            }

            $public = $this->state()->publicSitesFor($instance);
            $plan = $this->plan();

            foreach ($sites as $domain) {
                $this->blueprints()->site($domain);

                if (! in_array($domain, $public, true)) {
                    $pool = $this->state()->pool($domain);
                    $target = $pool['balancer'] ?? null;

                    $this->warn($target
                        ? "{$domain} is behind the load balancer {$target}, point DNS at it with: php artisan blacksmith:dns {$target}"
                        : "{$domain} is not hosted on {$instance}, skipped.");

                    continue;
                }

                $dns->handle($domain, $instance, $plan, (bool) $this->option('force'));
            }
        } catch (RuntimeException $exception) {
            return $this->failWith($exception);
        }

        $this->info("DNS is pointed at {$instance} ({$record['ip_address']}).");

        return self::SUCCESS;
    }
}
