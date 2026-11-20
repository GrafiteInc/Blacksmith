<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Plan;
use RuntimeException;

/**
 * Move an instance to a brand new server instead of upgrading its OS in place.
 *
 *  build:   {instance}-next gets the sites and a deploy, but no scheduler, workers or certificate
 *  cutover: the old server stops its scheduler and workers, the names are swapped so the new
 *           server becomes {instance}, DNS is pointed at it (when a DNS provider is set), then it
 *           gets the scheduler, workers and certificate
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

        return $name;
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

        foreach ($roles as $domain => $role) {
            if ($role === 'primary') {
                $this->site->handle($instance, $domain, $plan, ['scheduler', 'workers'], 'web');
            }
        }

        $this->state->promoteReplacement($instance, $retired);

        // A DNS failure must not leave the site without its scheduler and workers, only the certificate waits for it.
        $dnsError = null;

        if ($dns && PointDns::enabled()) {
            try {
                foreach ($this->state->publicSitesFor($instance) as $domain) {
                    $this->dns->handle($domain, $instance, $plan);
                }
            } catch (RuntimeException $exception) {
                $dnsError = $exception;
                $plan->note('DNS: '.$exception->getMessage(), 'error');
            }
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

    public function finish(string $instance, Plan $plan): string
    {
        $retired = $this->retired($instance);

        $this->destroy->handle($retired, $plan);

        return $retired;
    }

    public function abort(string $instance, Plan $plan): string
    {
        $replacement = $this->replacement($instance);

        $this->destroy->handle($replacement, $plan);

        return $replacement;
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
