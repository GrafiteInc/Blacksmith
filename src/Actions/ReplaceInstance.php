<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Defaults;
use Grafite\Blacksmith\Support\Plan;
use Grafite\Blacksmith\Support\Poll;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Move an instance to a brand new server instead of upgrading its OS in place.
 *
 *  build:   {instance}-next gets the sites and a deploy, but no scheduler or workers. With dns-01
 *           it also gets its certificate, which doesn't depend on DNS, so HTTPS works from cutover
 *  cutover: refuses unless every site on the new server is deployed. The old server stops its
 *           scheduler and workers, the names are swapped so the new server becomes {instance},
 *           DNS is pointed at it (when a DNS provider is set), then it gets the scheduler, workers
 *           and certificate. The old server is kept for as long as DNS caches can still reach it
 *  finish:  the old server, kept as {instance}-old, is deleted
 *  abort:   the new server is deleted before cutover
 */
class ReplaceInstance
{
    public function __construct(
        protected StateRepository $state,
        protected BuildInstance $build,
        protected SyncSite $site,
        protected ConfigureBalancer $balancer,
        protected DestroyInstance $destroy,
        protected PointDns $dns,
        protected ForgeGateway $forge,
        protected BlueprintRepository $blueprints,
    ) {}

    public function reportUsing(?callable $reporter): static
    {
        $this->build->reportUsing($reporter);

        return $this;
    }

    public static function replacementName(string $instance): string
    {
        return "{$instance}-next";
    }

    public static function retiredName(string $instance): string
    {
        return "{$instance}-old";
    }

    /**
     * Whether certificates are requested while building, dns-01 doesn't need DNS to point at the new server.
     */
    public static function certificateBeforeCutover(): bool
    {
        return Defaults::get('certificate.verification_method', 'http-01') === 'dns-01';
    }

    public function build(string $instance, string $profile, Plan $plan, ?string $ip = null, ?string $privateIp = null, bool $deploy = true): string
    {
        $record = $this->state->instance($instance);
        $sites = $this->state->sitesFor($instance);
        $name = static::replacementName($instance);

        if ($this->state->isTransient($instance)) {
            throw new RuntimeException("{$instance} is part of another replacement, replace the instance it belongs to.");
        }

        if (empty($record['server_id'])) {
            throw new RuntimeException("{$instance} does not have a Forge server yet.");
        }

        if (empty($sites)) {
            throw new RuntimeException("{$instance} has no sites to move.");
        }

        if ($existing = $this->state->replacementFor($instance)) {
            throw new RuntimeException("{$instance} is already being replaced by {$existing}, run blacksmith:replace {$instance} --cutover or --abort.");
        }

        if ($retired = $this->state->retiredFor($instance)) {
            throw new RuntimeException("{$instance} still has its old server {$retired}, run blacksmith:replace {$instance} --finish first.");
        }

        if ($this->state->hasInstance($name)) {
            throw new RuntimeException("Instance [{$name}] already exists.");
        }

        $this->build->handle($name, $profile, $sites, $plan, $ip, $privateIp, null, $deploy, ['replaces' => $instance]);

        if (static::certificateBeforeCutover()) {
            $this->certificatesBeforeCutover($instance, $name, $plan);
        }

        return $name;
    }

    /**
     * Get the replacement's certificates now, a failure only means cutover requests them instead.
     */
    protected function certificatesBeforeCutover(string $instance, string $replacement, Plan $plan): void
    {
        foreach ($this->state->sitesFor($instance) as $domain) {
            $role = $this->state->roleFor($instance, $domain);

            if (! in_array($role, ['primary', 'balancer'], true) || ($role === 'primary' && $this->state->isPooled($instance, $domain))) {
                continue;
            }

            try {
                $this->site->handle($replacement, $domain, $plan, ['ssl'], $role);
            } catch (RuntimeException $exception) {
                $plan->note("{$replacement} {$domain}: The certificate wasn't requested, cutover will request it: {$exception->getMessage()}");
            }
        }
    }

    public function cutover(string $instance, Plan $plan, bool $dns = true): string
    {
        $replacement = $this->replacement($instance);
        $retired = static::retiredName($instance);

        if (empty($this->state->instance($replacement)['server_id'])) {
            throw new RuntimeException("{$replacement} did not finish building, run blacksmith:replace {$instance} --abort and try again.");
        }

        if ($this->state->hasInstance($retired)) {
            throw new RuntimeException("Instance [{$retired}] already exists, run blacksmith:replace {$instance} --finish first.");
        }

        $roles = collect($this->state->sitesFor($instance))
            ->mapWithKeys(fn ($domain) => [$domain => $this->state->roleFor($instance, $domain)]);

        $this->ensureDeployed($replacement, $roles->toArray());

        foreach ($roles as $domain => $role) {
            if ($role === 'primary') {
                $this->site->handle($instance, $domain, $plan, ['scheduler', 'workers'], 'web');
            }
        }

        $this->state->promoteReplacement($instance, $retired);

        // A DNS failure must not leave the site without its scheduler and workers, only the certificate waits for it.
        $dnsError = null;
        $drain = 0;

        if ($dns && PointDns::enabled()) {
            try {
                foreach ($this->state->publicSitesFor($instance) as $domain) {
                    $drain = max($drain, $this->dns->handle($domain, $instance, $plan));
                }
            } catch (RuntimeException $exception) {
                $dnsError = $exception;
                $plan->note('DNS: '.$exception->getMessage(), 'error');
            }
        }

        if ($drain > 0) {
            $this->state->putInstance($retired, ['drain_until' => Carbon::now()->addSeconds($drain)->toIso8601String()]);
        }

        try {
            foreach ($roles as $domain => $role) {
                $pooled = $this->state->isPooled($instance, $domain);

                $sections = match ($role) {
                    'primary' => $pooled ? ['scheduler', 'workers'] : ['scheduler', 'workers', 'ssl'],
                    'balancer' => ['ssl'],
                    default => [],
                };

                if ($dnsError) {
                    $sections = array_values(array_diff($sections, ['ssl']));
                }

                if ($sections) {
                    $this->site->handle($instance, $domain, $plan, $sections);
                }

                if (in_array('ssl', $sections, true)) {
                    $this->waitForCertificate($instance, $domain, $plan);
                }

                if ($pooled) {
                    $this->balancer->handle($domain, $plan);
                }
            }
        } catch (RuntimeException $exception) {
            throw new RuntimeException($exception->getMessage().PHP_EOL."{$instance} now points at the new server, finish with: php artisan blacksmith:update-server {$instance}", 0, $exception);
        }

        if ($dnsError) {
            throw new RuntimeException("{$instance} is on the new server with its scheduler and workers, but DNS could not be updated: {$dnsError->getMessage()}".PHP_EOL
                ."Finish with: php artisan blacksmith:dns {$instance} && php artisan blacksmith:update-server {$instance} --only=ssl", 0, $dnsError);
        }

        return $retired;
    }

    public function finish(string $instance, Plan $plan, bool $force = false): string
    {
        $retired = $this->retired($instance);

        if (! $force) {
            $this->ensureDrained($instance);
        }

        $this->destroy->handle($retired, $plan);

        return $retired;
    }

    public function abort(string $instance, Plan $plan): string
    {
        $replacement = $this->replacement($instance);

        $this->destroy->handle($replacement, $plan);

        return $replacement;
    }

    /**
     * Until when DNS caches may still send visitors to the old server, null once that has passed.
     */
    public function drainUntil(string $instance): ?Carbon
    {
        $retired = $this->state->retiredFor($instance);
        $until = $retired ? ($this->state->instance($retired)['drain_until'] ?? null) : null;

        if (! $until) {
            return null;
        }

        $until = Carbon::parse($until);

        return $until->isFuture() ? $until : null;
    }

    public function ensureDrained(string $instance): void
    {
        if ($until = $this->drainUntil($instance)) {
            $retired = $this->retired($instance);

            throw new RuntimeException("DNS caches can still send visitors to {$retired} until {$until->toDateTimeString()} (the records' TTL), deleting it now would take the site down for them. Run --finish after that, or pass --force.");
        }
    }

    /**
     * Every site on the replacement must be live before visitors are sent to it.
     *
     * @param  array<string, string>  $roles  domain => role on the instance being replaced
     */
    protected function ensureDeployed(string $replacement, array $roles): void
    {
        $serverId = (int) $this->state->instance($replacement)['server_id'];

        foreach ($roles as $domain => $role) {
            $siteId = $this->state->siteId($replacement, $domain);

            if ($role === 'balancer' || ! $siteId) {
                continue;
            }

            $deployment = $this->forge->latestDeployment($serverId, $siteId);

            if ($deployment?->status !== ForgeGateway::DEPLOYMENT_FINISHED) {
                $status = $deployment ? "its last deployment is {$deployment->status}" : 'it has never been deployed';

                throw new RuntimeException("{$replacement} {$domain}: {$status}, so cutover would send visitors to a site without its code. Deploy it first: php artisan blacksmith:update-server {$replacement} --site={$domain} --deploy");
            }
        }
    }

    /**
     * Wait for the certificate, until then HTTPS on the new server fails.
     */
    protected function waitForCertificate(string $instance, string $domain, Plan $plan): void
    {
        if ($plan->dryRun || ! $this->blueprints->site($domain)['lets_encrypt']) {
            return;
        }

        $serverId = (int) $this->state->instance($instance)['server_id'];
        $siteId = $this->state->siteId($instance, $domain);

        $active = $siteId && Poll::until(
            fn () => $this->forge->site($serverId, $siteId)?->https,
            (int) config('blacksmith.certificate_timeout', 300),
            (int) config('blacksmith.poll_interval', 15),
        );

        $active
            ? $plan->note("{$instance} {$domain}: Certificate is active", 'info')
            : $plan->note("{$instance} {$domain}: The certificate isn't active yet and HTTPS on the new server fails until it is, check it in Forge.");
    }

    public function replacement(string $instance): string
    {
        return $this->state->replacementFor($instance)
            ?? throw new RuntimeException("{$instance} is not being replaced, run blacksmith:replace {$instance} first.");
    }

    public function retired(string $instance): string
    {
        return $this->state->retiredFor($instance)
            ?? throw new RuntimeException("{$instance} has no old server to delete.");
    }
}
