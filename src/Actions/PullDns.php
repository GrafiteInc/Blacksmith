<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\ReportsProgress;
use RuntimeException;

/**
 * Find a site's DNS zone and report where its domain and aliases point.
 * Problems are warnings, DNS never stops a localize.
 */
class PullDns
{
    use ReportsProgress;

    public function __construct(protected PointDns $dns, protected StateRepository $state) {}

    public function handle(string $domain): void
    {
        if (! $this->dns->manages($domain)) {
            return;
        }

        $provider = PointDns::providerName();

        try {
            $zone = $this->dns->provider()->zoneFor($domain);

            if (! $zone) {
                $this->report("{$domain}: no {$provider} zone found, set \"dns\": false in its site.json if its DNS lives elsewhere.", 'warn');

                return;
            }

            $this->state->putDnsZone($domain, $zone);

            foreach ($this->dns->hostnames($domain) as $hostname) {
                $this->reportHostname($hostname);
            }
        } catch (RuntimeException $exception) {
            $this->report("{$domain}: could not read DNS from {$provider}, {$exception->getMessage()}", 'warn');
        }
    }

    protected function reportHostname(string $hostname): void
    {
        $records = collect($this->dns->provider()->records($hostname));

        if ($records->isEmpty()) {
            $this->report("DNS {$hostname}: no record, one will be created when DNS is pointed at an instance.", 'warn');

            return;
        }

        foreach ($records as $record) {
            $proxied = $record['proxied'] ? ' (proxied)' : '';

            if ($record['type'] === 'CNAME') {
                $this->report("DNS {$hostname}: CNAME follows {$record['content']}{$proxied}");

                continue;
            }

            $instance = $this->state->instanceForIp($record['content']);

            $instance
                ? $this->report("DNS {$hostname}: {$record['type']} {$record['content']} → {$instance}{$proxied}")
                : $this->report("DNS {$hostname}: {$record['type']} {$record['content']}{$proxied} is not a Blacksmith instance, it won't be moved without --force.", 'warn');
        }
    }
}
