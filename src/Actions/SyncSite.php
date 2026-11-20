<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Defaults;
use Grafite\Blacksmith\Support\Php;
use Grafite\Blacksmith\Support\Plan;
use Illuminate\Support\Str;
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
        protected ForgeGateway $forge,
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
            $this->updateSite($instance, $domain, $serverId, $site, $blueprint, $role, $plan, $label);
        }

        if ($role !== 'balancer') {
            if ($should('repository') && $blueprint['repository']) {
                $this->syncRepository($serverId, $site, $created, $blueprint['repository'], $plan, $label);
            }

            if ($should('env')) {
                $this->syncFile('environment', $this->blueprints->env($domain, $this->state->blueprintName($instance)), $serverId, $siteId, $created, $plan, $label);
            }

            if ($should('deploy')) {
                $script = $this->blueprints->deployScript($domain);

                if ($mismatch = static::deployScriptMismatch($this->blueprints->zeroDowntime($domain), $script)) {
                    $plan->note($label.$mismatch);
                }

                $this->syncFile('deployment script', $script, $serverId, $siteId, $created, $plan, $label);
            }

            if ($should('scheduler')) {
                $this->syncScheduler($serverId, $domain, $site, $role === 'primary' && $blueprint['scheduler'], $plan, $label);
            }

            if ($should('workers')) {
                $this->workers->sync($serverId, $site, $role === 'primary' ? $blueprint['workers'] : [], $plan, $label);
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
            $this->balancer->handle($domain, $plan, [], $instance);
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

        $site = $plan->step($label.'Create site', fn () => $role === 'balancer'
            ? $this->forge->createBalancer($serverId, [
                'domain' => $domain,
                'balancer_method' => $this->state->pool($domain)['method'] ?? Defaults::get('balancer.method', 'round_robin'),
            ])
            : $this->forge->createSite($serverId, static::createPayload($blueprint, $this->blueprints->zeroDowntime($domain))));

        if ($site) {
            $this->state->setSiteId($instance, $domain, $site->id);
        }

        if (! empty($blueprint['aliases'])) {
            $plan->step($label.'Add aliases '.implode(', ', $blueprint['aliases']), fn () => $this->forge->syncAliases($serverId, $site->id, $domain, $blueprint['aliases']));
        }

        return [$site, true];
    }

    protected function updateSite(string $instance, string $domain, int $serverId, Site $site, array $blueprint, string $role, Plan $plan, string $label): void
    {
        $aliases = collect($blueprint['aliases'])->sort()->values()->toArray();
        $current = collect(array_diff($site->aliases ?? [], [$site->name]))->sort()->values()->toArray();

        if ($aliases !== $current) {
            $plan->step($label.'Update aliases', fn () => $this->forge->syncAliases($serverId, $site->id, $site->name, $blueprint['aliases']));
        }

        if ($role === 'balancer') {
            return;
        }

        $zeroDowntime = $this->blueprints->zeroDowntime($domain);

        if (! is_null($site->zeroDowntimeDeployments) && $site->zeroDowntimeDeployments !== $zeroDowntime) {
            $plan->note($label.'The site '.($site->zeroDowntimeDeployments ? 'uses' : 'does not use').' zero-downtime deployments but the blueprint '
                .($zeroDowntime ? 'does' : 'does not').". Forge only sets this when a site is created, run blacksmith:replace {$this->state->blueprintName($instance)} to move it to a new server.");
        }

        if ($site->webDirectory && $site->webDirectory !== $blueprint['directory']) {
            $plan->step($label."Change web directory to {$blueprint['directory']}", fn () => $this->forge->updateSite($serverId, $site->id, [
                'directory' => $blueprint['directory'],
            ]));
        }

        if ($site->phpVersion && $site->phpVersion !== $blueprint['php_version']) {
            $plan->step($label."Change PHP to {$blueprint['php_version']}", fn () => $this->forge->updateSite($serverId, $site->id, [
                'php_version' => $blueprint['php_version'],
            ]));
        }
    }

    /**
     * The v2 create site payload, the repository is installed as part of it.
     * Zero-downtime deployments are always sent, Forge turns them on when they're left out.
     */
    public static function createPayload(array $blueprint, ?bool $zeroDowntime = null): array
    {
        $payload = [
            'name' => $blueprint['domain'],
            'type' => static::siteType($blueprint['project_type'] ?? null),
            'web_directory' => $blueprint['directory'],
            'is_isolated' => false,
            'php_version' => $blueprint['php_version'],
            'zero_downtime_deployments' => $zeroDowntime ?? (bool) ($blueprint['zero_downtime'] ?? false),
        ];

        if ($repository = $blueprint['repository'] ?? null) {
            $repository = static::repository($repository);

            $payload += [
                'source_control_provider' => $repository['provider'],
                'repository' => $repository['repository'],
                'branch' => $repository['branch'],
                'install_composer_dependencies' => (bool) $repository['composer'],
            ];
        }

        return $payload;
    }

    /**
     * v1 project types were php and html, v2 has a type per framework. Sites are Laravel apps unless the blueprint says otherwise.
     */
    public static function siteType(?string $type): string
    {
        return match ($type) {
            null, '' => Defaults::get('site.project_type', 'laravel'),
            'html' => 'static-html',
            default => $type,
        };
    }

    /**
     * A blueprint repository with the configured defaults filled in.
     */
    public static function repository(array $repository): array
    {
        return array_replace(['provider' => 'github', 'repository' => null, 'branch' => 'main', 'composer' => true], Defaults::section('repository'), $repository);
    }

    protected function syncRepository(int $serverId, ?Site $site, bool $created, array $repository, Plan $plan, string $label): void
    {
        $repository = static::repository($repository);

        if ($created || ! $site) {
            return;
        }

        $current = ForgeGateway::siteRepository($site);

        if (! $current || $current['repository'] !== $repository['repository']) {
            $plan->note($label."Forge can't change a site's repository after it is created, delete the site and run update-server to recreate it with {$repository['repository']}.");

            return;
        }

        if ($current['branch'] !== $repository['branch']) {
            $plan->step($label."Switch branch to {$repository['branch']}", fn () => $this->forge->updateSite($serverId, $site->id, [
                'repository_branch' => $repository['branch'],
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

    protected function syncScheduler(int $serverId, string $domain, ?Site $site, bool $enabled, Plan $plan, string $label): void
    {
        $job = collect($this->forge->scheduledJobs($serverId))->first(fn ($job) => static::isSchedulerFor($job->command, $domain));
        $artisan = $this->siteRoot($domain, $site).'/artisan schedule:run';

        // A scheduler outside the code's directory, such as one missing /current, never runs anything.
        if ($enabled && $job && ! Str::contains((string) $job->command, $artisan)) {
            $plan->step($label."Move scheduler to {$artisan}", fn () => $this->forge->deleteScheduledJob($serverId, $job->id));
            $job = null;
        }

        if ($enabled && ! $job) {
            $plan->step($label.'Create scheduler', fn () => $this->forge->createScheduledJob($serverId, [
                'name' => "{$domain} scheduler",
                'command' => Php::binary($this->blueprints->site($domain)['php_version']).' '.$artisan,
                'frequency' => Defaults::get('scheduler.frequency', 'minutely'),
                'user' => Defaults::user(),
            ]));
        }

        if (! $enabled && $job) {
            $plan->step($label.'Remove scheduler', fn () => $this->forge->deleteScheduledJob($serverId, $job->id));
        }
    }

    /**
     * Where the site's code runs from, worked out from the blueprint when the site doesn't exist yet (a dry run).
     */
    protected function siteRoot(string $domain, ?Site $site): string
    {
        if ($site) {
            return ForgeGateway::siteRoot($site);
        }

        return '/home/'.Defaults::user()."/{$domain}".($this->blueprints->zeroDowntime($domain) ? '/current' : '');
    }

    /**
     * Why a deploy script won't work with the site's deployment style, if it won't.
     */
    public static function deployScriptMismatch(bool $zeroDowntime, string $script): ?string
    {
        if (trim($script) === '') {
            return null;
        }

        $releases = BlueprintRepository::usesReleases($script);

        if ($zeroDowntime && ! $releases) {
            return 'The site uses zero-downtime deployments but deploy.sh has no $CREATE_RELEASE() / $ACTIVATE_RELEASE(), so deploys would run in place. Use Forge\'s zero-downtime deploy script.';
        }

        if (! $zeroDowntime && $releases) {
            return 'deploy.sh uses $CREATE_RELEASE() / $ACTIVATE_RELEASE() but the site does not use zero-downtime deployments, set "zero_downtime": true or use a regular deploy script.';
        }

        return null;
    }

    protected function syncCertificate(int $serverId, ?Site $site, bool $created, array $blueprint, string $role, Plan $plan, string $label): void
    {
        if ($created && $role === 'balancer') {
            $plan->note($label.'Point DNS at the balancer, then run `blacksmith:update-server --only=ssl` to get the certificate.');

            return;
        }

        if (! $created && $site?->https) {
            return;
        }

        $domains = $blueprint['ssl_domains'] ?: array_merge([$blueprint['domain']], $blueprint['aliases']);

        $plan->step($label.'Request Let\'s Encrypt certificate for '.implode(', ', $domains), fn () => $this->forge->obtainLetsEncryptCertificate($serverId, $site->id, $domains));
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
                $this->forge->createRedirectRule($serverId, $siteId, $rule);
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

        $existing = $siteId ? collect($this->forge->securityRules($serverId, $siteId)) : collect();

        // API v2 leaves credentials out of security rules, users are only compared when Forge sends them.
        $withUsers = $existing->every(fn ($rule) => array_key_exists('credentials', $rule->attributes));

        $normalize = fn ($rule) => array_filter([
            'name' => $rule['name'],
            'path' => $rule['path'] ?? null,
            'users' => $withUsers ? collect($rule['credentials'] ?? [])->pluck('username')->sort()->values()->toArray() : null,
        ], fn ($value) => ! is_null($value));

        $signature = fn ($rules) => $rules->map(fn ($rule) => json_encode($rule))->sort()->values()->toArray();

        if ($signature($desired->map($normalize)) === $signature($existing->map(fn ($rule) => $normalize($rule->attributes)))) {
            return;
        }

        $plan->step($label."Replace security rules ({$desired->count()})", function () use ($serverId, $siteId, $existing, $desired) {
            foreach ($existing as $rule) {
                $this->forge->deleteSecurityRule($serverId, $siteId, $rule->id);
            }

            foreach ($desired as $rule) {
                $this->forge->createSecurityRule($serverId, $siteId, $rule);
            }
        });
    }

    /**
     * The site's scheduler, run from the site's directory or its current release.
     */
    public static function isSchedulerFor(?string $command, string $domain): bool
    {
        return (bool) preg_match('#/'.preg_quote($domain, '#').'(?:/current)?/artisan schedule:run#', (string) $command);
    }
}
