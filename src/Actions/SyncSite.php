<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Php;
use Grafite\Blacksmith\Support\Plan;
use Illuminate\Support\Str;
use Laravel\Forge\Forge;
use Laravel\Forge\Resources\Site;

/**
 * Push a site blueprint to an instance, creating the site when it is missing.
 *
 * Roles change what is pushed:
 *  - primary: everything
 *  - web: no scheduler or workers, and no certificate since the balancer terminates SSL
 *  - balancer: the site, certificate, redirects, security and node balancing
 */
class SyncSite
{
    public const SECTIONS = [
        'site',
        'repository',
        'env',
        'deploy',
        'scheduler',
        'workers',
        'ssl',
        'quick_deploy',
        'redirects',
        'security',
        'balancing',
    ];

    public function __construct(
        protected Forge $forge,
        protected BlueprintRepository $blueprints,
        protected StateRepository $state,
        protected SyncWorkers $workers,
        protected ConfigureBalancer $balancer,
    ) {}

    public function handle(string $instance, string $domain, Plan $plan, array $only = [], ?string $role = null): ?int
    {
        $serverId = (int) $this->state->instance($instance)['server_id'];
        $blueprint = $this->blueprints->site($domain);
        $role ??= $this->state->roleFor($instance, $domain);
        $label = "{$instance} {$domain}: ";
        $should = fn (string $section) => empty($only) || in_array($section, $only, true);

        [$site, $created] = $this->resolveSite($instance, $domain, $serverId, $blueprint, $role, $plan, $label);
        $siteId = $site?->id;

        if (! $created && $site && $should('site')) {
            $this->updateSite($serverId, $site, $blueprint, $role, $plan, $label);
        }

        if ($role !== 'balancer') {
            if ($should('repository') && $blueprint['repository']) {
                $this->syncRepository($serverId, $site, $created, $blueprint['repository'], $plan, $label);
            }

            if ($should('env')) {
                $this->syncFile('environment', $this->blueprints->env($domain, $instance), $serverId, $siteId, $created, $plan, $label);
            }

            if ($should('deploy')) {
                $this->syncFile('deployment script', $this->blueprints->deployScript($domain), $serverId, $siteId, $created, $plan, $label);
            }

            if ($should('scheduler')) {
                $this->syncScheduler($serverId, $domain, $role === 'primary' && $blueprint['scheduler'], $plan, $label);
            }

            if ($should('workers')) {
                $this->workers->sync($serverId, $siteId, $role === 'primary' ? $blueprint['workers'] : [], $plan, $label);
            }

            if ($should('quick_deploy') && (bool) $blueprint['quick_deploy'] !== (bool) $site?->quickDeploy) {
                $plan->step($label.($blueprint['quick_deploy'] ? 'Enable' : 'Disable').' quick deploy', fn () => $blueprint['quick_deploy']
                    ? $this->forge->enableQuickDeploy($serverId, $siteId)
                    : $this->forge->disableQuickDeploy($serverId, $siteId));
            }
        }

        if ($should('ssl') && $blueprint['lets_encrypt'] && $role !== 'web') {
            $this->syncCertificate($serverId, $site, $created, $blueprint, $role, $plan, $label);
        }

        if ($should('redirects')) {
            $this->syncRedirects($serverId, $siteId, $blueprint['redirects'] ?? [], $plan, $label);
        }

        if ($should('security')) {
            $this->syncSecurity($serverId, $siteId, $blueprint['security'] ?? [], $plan, $label);
        }

        if ($should('balancing') && $role === 'balancer') {
            $this->balancer->handle($domain, $plan);
        }

        return $siteId;
    }

    /**
     * @return array{0: ?Site, 1: bool}
     */
    protected function resolveSite(string $instance, string $domain, int $serverId, array $blueprint, string $role, Plan $plan, string $label): array
    {
        $sites = collect($this->forge->sites($serverId));
        $siteId = $this->state->siteId($instance, $domain);

        $site = ($siteId ? $sites->firstWhere('id', $siteId) : null) ?? $sites->firstWhere('name', $domain);

        if ($site) {
            if ($site->id !== $siteId && ! $plan->dryRun) {
                $this->state->setSiteId($instance, $domain, $site->id);
            }

            return [$site, false];
        }

        if ($default = $sites->firstWhere('name', 'default')) {
            $plan->step("{$instance}: Remove the default site", fn () => $this->forge->deleteSite($serverId, $default->id));
        }

        $payload = [
            'domain' => $domain,
            'aliases' => $blueprint['aliases'],
            'project_type' => $blueprint['project_type'],
            'directory' => $blueprint['directory'],
            'isolated' => false,
        ];

        if ($role !== 'balancer') {
            $payload['php_version'] = $blueprint['php_version'];
        }

        $site = $plan->step($label.'Create site', fn () => $this->forge->createSite($serverId, $payload, true));

        if ($site) {
            $this->state->setSiteId($instance, $domain, $site->id);
        }

        return [$site, true];
    }

    protected function updateSite(int $serverId, Site $site, array $blueprint, string $role, Plan $plan, string $label): void
    {
        $aliases = collect($blueprint['aliases'])->sort()->values()->toArray();
        $current = collect($site->aliases ?? [])->sort()->values()->toArray();

        if ($site->directory !== $blueprint['directory'] || $aliases !== $current) {
            $plan->step($label.'Update directory and aliases', fn () => $this->forge->updateSite($serverId, $site->id, [
                'directory' => $blueprint['directory'],
                'aliases' => $blueprint['aliases'],
            ]));
        }

        if ($role !== 'balancer' && $site->phpVersion && $site->phpVersion !== $blueprint['php_version']) {
            $plan->step($label."Change PHP to {$blueprint['php_version']}", fn () => $this->forge->changeSitePHPVersion($serverId, $site->id, $blueprint['php_version']));
        }
    }

    protected function syncRepository(int $serverId, ?Site $site, bool $created, array $repository, Plan $plan, string $label): void
    {
        $repository = array_replace(['provider' => 'github', 'branch' => 'main', 'composer' => true], $repository);

        if ($created || empty($site?->repository)) {
            $plan->step($label."Install repository {$repository['repository']}#{$repository['branch']}", fn () => $this->forge->installGitRepositoryOnSite($serverId, $site->id, $repository, true));

            return;
        }

        if ($site->repository !== $repository['repository'] || $site->repositoryBranch !== $repository['branch']) {
            $plan->step($label."Point repository at {$repository['repository']}#{$repository['branch']}", fn () => $this->forge->updateSiteGitRepository($serverId, $site->id, [
                'provider' => $repository['provider'],
                'repository' => $repository['repository'],
                'branch' => $repository['branch'],
            ]));
        }
    }

    protected function syncFile(string $type, string $contents, int $serverId, ?int $siteId, bool $created, Plan $plan, string $label): void
    {
        if (trim($contents) === '') {
            return;
        }

        $environment = $type === 'environment';

        if (! $created && $siteId) {
            $current = $environment
                ? $this->forge->siteEnvironmentFile($serverId, $siteId)
                : $this->forge->siteDeploymentScript($serverId, $siteId);

            if (trim((string) $current) === trim($contents)) {
                return;
            }
        }

        $plan->step($label."Update {$type}", fn () => $environment
            ? $this->forge->updateSiteEnvironmentFile($serverId, $siteId, $contents)
            : $this->forge->updateSiteDeploymentScript($serverId, $siteId, $contents));
    }

    protected function syncScheduler(int $serverId, string $domain, bool $enabled, Plan $plan, string $label): void
    {
        $job = collect($this->forge->jobs($serverId))->first(fn ($job) => static::isSchedulerFor($job->command, $domain));

        if ($enabled && ! $job) {
            $plan->step($label.'Create scheduler', fn () => $this->forge->createJob($serverId, [
                'command' => Php::binary($this->blueprints->site($domain)['php_version'])." /home/forge/{$domain}/artisan schedule:run",
                'frequency' => 'minutely',
                'user' => 'forge',
            ], false));
        }

        if (! $enabled && $job) {
            $plan->step($label.'Remove scheduler', fn () => $this->forge->deleteJob($serverId, $job->id));
        }
    }

    protected function syncCertificate(int $serverId, ?Site $site, bool $created, array $blueprint, string $role, Plan $plan, string $label): void
    {
        if ($created && $role === 'balancer') {
            $plan->note($label.'Point DNS at the balancer, then run `blacksmith:update-server --only=ssl` to get the certificate.');

            return;
        }

        if (! $created && $site?->isSecured) {
            return;
        }

        $domains = $blueprint['ssl_domains'] ?: array_merge([$blueprint['domain']], $blueprint['aliases']);

        $plan->step($label.'Request Let\'s Encrypt certificate for '.implode(', ', $domains), fn () => $this->forge->obtainLetsEncryptCertificate($serverId, $site->id, [
            'domains' => $domains,
        ], false));
    }

    protected function syncRedirects(int $serverId, ?int $siteId, array $redirects, Plan $plan, string $label): void
    {
        $normalize = fn ($rule) => ['from' => $rule['from'], 'to' => $rule['to'], 'type' => $rule['type'] ?? 'redirect'];

        $desired = collect($redirects)->filter(fn ($rule) => ! empty($rule['from']))->map($normalize)->values();
        $existing = $siteId ? collect($this->forge->redirectRules($serverId, $siteId)) : collect();

        $signature = fn ($rules) => $rules->map(fn ($rule) => json_encode($rule))->sort()->values()->toArray();

        if ($signature($desired) === $signature($existing->map(fn ($rule) => $normalize($rule->attributes)))) {
            return;
        }

        $plan->step($label."Replace redirects ({$desired->count()})", function () use ($serverId, $siteId, $existing, $desired) {
            foreach ($existing as $rule) {
                $this->forge->deleteRedirectRule($serverId, $siteId, $rule->id);
            }

            foreach ($desired as $rule) {
                $this->forge->createRedirectRule($serverId, $siteId, $rule, false);
            }
        });
    }

    protected function syncSecurity(int $serverId, ?int $siteId, array $rules, Plan $plan, string $label): void
    {
        $desired = collect($rules)->filter(fn ($rule) => ! empty($rule['name']));

        $missingPasswords = $desired->contains(fn ($rule) => collect($rule['credentials'] ?? [])->contains(fn ($credential) => empty($credential['password'])));

        if ($missingPasswords) {
            $plan->note($label.'Skipped security rules, Forge does not return passwords so fill them in to site.json first.');

            return;
        }

        $normalize = fn ($rule) => [
            'name' => $rule['name'],
            'path' => $rule['path'] ?? null,
            'users' => collect($rule['credentials'] ?? [])->pluck('username')->sort()->values()->toArray(),
        ];

        $existing = $siteId ? collect($this->forge->securityRules($serverId, $siteId)) : collect();

        $signature = fn ($rules) => $rules->map(fn ($rule) => json_encode($rule))->sort()->values()->toArray();

        if ($signature($desired->map($normalize)) === $signature($existing->map(fn ($rule) => $normalize($rule->attributes)))) {
            return;
        }

        $plan->step($label."Replace security rules ({$desired->count()})", function () use ($serverId, $siteId, $existing, $desired) {
            foreach ($existing as $rule) {
                $this->forge->deleteSecurityRule($serverId, $siteId, $rule->id);
            }

            foreach ($desired as $rule) {
                $this->forge->createSecurityRule($serverId, $siteId, $rule, false);
            }
        });
    }

    public static function isSchedulerFor(?string $command, string $domain): bool
    {
        return Str::contains((string) $command, "/{$domain}/artisan schedule:run");
    }
}
