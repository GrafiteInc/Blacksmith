<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\Dns\DnsProvider;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Plan;
use RuntimeException;

/**
 * Point a site's domain and aliases at an instance.
 *
 * A record is only moved when it points at a server Blacksmith knows about,
 * so records aimed at a CDN or another host are never overwritten by accident.
 * A CNAME to one of your sites already follows that site and is left alone.
 */
class PointDns
{
    public function __construct(protected BlueprintRepository $blueprints, protected StateRepository $state) {}

    public static function enabled(): bool
    {
        return ! empty(config('blacksmith.dns.provider'));
    }

    public static function providerName(): string
    {
        return (string) config('blacksmith.dns.provider');
    }

    /**
     * Whether DNS is managed for the site, sites can opt out with "dns": false.
     */
    public function manages(string $domain): bool
    {
        return static::enabled() && ($this->blueprints->site($domain)['dns'] ?? true) !== false;
    }

    public function hostnames(string $domain): array
    {
        $site = $this->blueprints->site($domain);

        return collect(array_merge([$site['domain']], $site['aliases'] ?? []))
            ->map(fn ($hostname) => static::normalize($hostname))
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Cloudflare's "automatic" TTL, in seconds.
     */
    public const AUTOMATIC_TTL = 300;

    /**
     * Point the records and return how long, in seconds, resolvers may keep sending visitors
     * to the old address. Proxied records switch at the edge, so they don't count.
     */
    public function handle(string $domain, string $instance, Plan $plan, bool $force = false): int
    {
        if (! static::enabled()) {
            throw new RuntimeException('DNS is not configured, set BLACKSMITH_DNS_PROVIDER=cloudflare and BLACKSMITH_CLOUDFLARE_TOKEN.');
        }

        if (! $this->manages($domain)) {
            $plan->note("{$domain}: DNS is managed by hand (\"dns\": false), skipped.");

            return 0;
        }

        $ip = $this->state->instance($instance)['ip_address'] ?? null;

        if (empty($ip)) {
            throw new RuntimeException("{$instance} has no IP address to point DNS at.");
        }

        $dns = $this->provider();
        $known = $this->state->knownIps();
        $managed = $this->managedHostnames();
        $drain = 0;

        foreach ($this->hostnames($domain) as $hostname) {
            $label = "DNS {$hostname}: ";
            $records = collect($dns->records($hostname));

            if ($cname = $records->firstWhere('type', 'CNAME')) {
                $target = static::normalize($cname['content']);

                in_array($target, $managed, true)
                    ? $plan->note($label."CNAME follows {$target}", 'info')
                    : $plan->note($label."is a CNAME to {$target}, which Blacksmith doesn't manage, skipped.");

                continue;
            }

            if ($records->where('type', 'AAAA')->isNotEmpty()) {
                $plan->note($label.'has AAAA records, Blacksmith only manages IPv4, check them in '.static::providerName().'.');
            }

            $a = $records->where('type', 'A')->values();

            if ($a->isEmpty()) {
                $plan->step($label."Create A record → {$ip}", fn () => $dns->createARecord($hostname, $ip));

                continue;
            }

            if ($a->count() > 1) {
                $plan->note($label.'has '.$a->count().' A records ('.$a->pluck('content')->implode(', ').'), update it in '.static::providerName().'.');

                continue;
            }

            $record = $a->first();

            if ($record['content'] === $ip) {
                $plan->note($label."already points at {$ip}", 'info');

                continue;
            }

            if (! $force && ! in_array($record['content'], $known, true)) {
                $plan->note($label."points at {$record['content']}, which Blacksmith doesn't manage, skipped (use blacksmith:dns --force).");

                continue;
            }

            $from = $this->state->instanceForIp($record['content']);
            $from = $from ? "{$record['content']} ({$from})" : $record['content'];

            $plan->step($label."Point A record {$from} → {$ip} ({$instance})", fn () => $dns->updateARecord($hostname, $record['id'], $ip));

            $drain = max($drain, static::drainSeconds($record));
        }

        return $drain;
    }

    /**
     * How long resolvers can keep a record's old address.
     */
    public static function drainSeconds(array $record): int
    {
        if (! empty($record['proxied'])) {
            return 0;
        }

        $ttl = (int) ($record['ttl'] ?? 0);

        return $ttl <= 1 ? static::AUTOMATIC_TTL : $ttl;
    }

    public function provider(): DnsProvider
    {
        return app(DnsProvider::class);
    }

    /**
     * Every domain and alias in the site blueprints.
     */
    protected function managedHostnames(): array
    {
        return collect($this->blueprints->sites())
            ->flatMap(fn ($domain) => $this->hostnames($domain))
            ->unique()
            ->values()
            ->toArray();
    }

    protected static function normalize(string $hostname): string
    {
        return strtolower(rtrim(trim($hostname), '.'));
    }
}
