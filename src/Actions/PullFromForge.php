<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Defaults;
use Grafite\Blacksmith\Support\EnvFile;
use Grafite\Blacksmith\Support\ReportsProgress;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Forge\Resources\Server;
use Laravel\Forge\Resources\ServerTypes;
use Laravel\Forge\Resources\Site;

/**
 * Copy a Forge server's configuration into blueprints and state.
 *
 * Only the site's primary instance writes the site blueprint, any other
 * instance of the same site has its env differences saved as .env.{instance}.
 */
class PullFromForge
{
    use ReportsProgress;

    public function __construct(
        protected ForgeGateway $forge,
        protected BlueprintRepository $blueprints,
        protected StateRepository $state,
        protected SyncWorkers $workers,
    ) {}

    public function server(int|string $serverId, ?string $instance = null, ?string $profile = null): string
    {
        $server = $this->forge->server((int) $serverId);

        $instance ??= $this->state->instanceForServer($server->id) ?? Str::slug($server->name);
        $existing = $this->state->hasInstance($instance) ? $this->state->instance($instance) : [];
        $profile ??= $existing['profile'] ?? static::profileNameFor($server->type);

        $this->pullProfile($server, $profile);

        $this->state->putInstance($instance, [
            'server_id' => $server->id,
            'ip_address' => $server->ipAddress,
            'private_ip_address' => $server->privateIpAddress,
            'profile' => $profile,
        ]);

        $this->report("{$instance}: Server {$server->name} ({$server->id}) using profile {$profile}");

        $sites = collect($this->forge->sites($server->id))->reject(fn ($site) => $site->name === 'default');

        if ($server->type === ServerTypes::LOADBALANCER) {
            foreach ($sites as $site) {
                $this->pullBalancer($instance, $server, $site);
            }

            return $instance;
        }

        $jobs = collect($this->forge->scheduledJobs($server->id));

        foreach ($sites as $site) {
            $this->pullSite($instance, $server, $site, $jobs);
        }

        return $instance;
    }

    public static function profileNameFor(?string $type): string
    {
        return match ($type) {
            ServerTypes::LOADBALANCER => 'balancer',
            null, '' => 'app',
            default => $type,
        };
    }

    protected function pullProfile(Server $server, string $name): void
    {
        $current = $this->blueprints->hasProfile($name)
            ? $this->blueprints->profile($name)
            : $this->blueprints::defaultProfile($server->type ?: ServerTypes::APP);

        $this->blueprints->saveProfile($name, array_replace($current, array_filter([
            'type' => $server->type,
            'ubuntu_version' => $server->ubuntuVersion,
            'php_version' => $server->phpVersion,
            'php_cli_version' => $server->phpCliVersion ?? $server->phpVersion,
            'database_type' => $server->databaseType,
        ]), [
            'opcache' => $server->opcacheStatus === 'enabled',
        ]));
    }

    protected function pullSite(string $instance, Server $server, Site $site, Collection $jobs): void
    {
        $domain = $site->name;

        $this->state->setSiteId($instance, $domain, $site->id);

        $role = $this->state->roleFor($instance, $domain);

        if ($role !== 'primary') {
            $this->report("{$instance} {$domain}: Recorded as a {$role} node, the blueprint comes from {$this->state->primaryInstanceFor($domain)}");

            return;
        }

        $env = (string) $this->forge->siteEnvironmentFile($server->id, $site->id);

        if ($this->blueprints->hasSite($domain) && $this->state->primaryInstanceFor($domain) !== $instance) {
            $this->pullEnvOverride($instance, $domain, $env);

            return;
        }

        $existing = $this->blueprints->hasSite($domain) ? $this->blueprints->site($domain) : $this->blueprints::defaultSite($domain);
        $aliases = array_values(array_diff($site->aliases ?? [], [$domain]));
        $repository = ForgeGateway::siteRepository($site);

        $this->blueprints->saveSite($domain, array_replace($existing, [
            'domain' => $domain,
            'aliases' => $aliases,
            'directory' => $site->webDirectory ?: $existing['directory'],
            'project_type' => $site->appType ?: $existing['project_type'],
            'php_version' => $site->phpVersion ?: ($existing['php_version'] ?? null),
            'repository' => $repository ? SyncSite::repository(array_filter([
                'provider' => $repository['provider'],
                'repository' => $repository['repository'],
                'branch' => $repository['branch'],
                'composer' => $existing['repository']['composer'] ?? null,
            ], fn ($value) => ! is_null($value))) : null,
            'lets_encrypt' => (bool) $site->https,
            'ssl_domains' => array_values(array_unique(array_merge([$domain], $aliases))),
            'quick_deploy' => (bool) $site->quickDeploy,
            'scheduler' => $jobs->contains(fn ($job) => SyncSite::isSchedulerFor($job->command, $domain)),
            'workers' => $this->workers->pull($server->id, $site),
            'redirects' => collect($this->forge->redirectRules($server->id, $site->id))->map(fn ($rule) => [
                'from' => $rule->from,
                'to' => $rule->to,
                'type' => $rule->type ?? 'redirect',
            ])->values()->toArray(),
            'security' => $this->pullSecurity($server->id, $site->id, $existing['security'] ?? []),
        ]));

        $this->blueprints->saveEnv($domain, $env);
        $this->blueprints->saveDeployScript($domain, (string) $this->forge->siteDeploymentScript($server->id, $site->id));

        $this->report("{$instance} {$domain}: Blueprint saved");
    }

    protected function pullEnvOverride(string $instance, string $domain, string $env): void
    {
        $diff = EnvFile::diff($this->blueprints->baseEnv($domain), $env);

        if ($diff !== '') {
            $this->blueprints->saveEnvOverride($domain, $instance, $diff);
            $this->report("{$instance} {$domain}: Env differences saved to .env.{$instance}");
        }

        $this->report("{$instance} {$domain}: Kept the blueprint from {$this->state->primaryInstanceFor($domain)}");
    }

    /**
     * Forge never returns passwords, so keep any we already have for the same user.
     */
    protected function pullSecurity(int $serverId, int $siteId, array $existing): array
    {
        $passwords = collect($existing)->flatMap(fn ($rule) => collect($rule['credentials'] ?? [])
            ->mapWithKeys(fn ($credential) => [($rule['name'] ?? '').'|'.($credential['username'] ?? '') => $credential['password'] ?? '']));

        return collect($this->forge->securityRules($serverId, $siteId))->map(fn ($rule) => [
            'name' => $rule->name,
            'path' => $rule->path,
            'credentials' => collect($rule->attributes['credentials'] ?? $this->existingUsers($existing, $rule->name))->map(fn ($credential) => [
                'username' => $credential['username'],
                'password' => $passwords->get($rule->name.'|'.$credential['username'], ''),
            ])->values()->toArray(),
        ])->values()->toArray();
    }

    /**
     * API v2 leaves credentials out of security rules, so keep the users we already know.
     */
    protected function existingUsers(array $existing, ?string $name): array
    {
        return collect($existing)->firstWhere('name', $name)['credentials'] ?? [];
    }

    /**
     * Record the pool behind a balancer, pulling any node servers we don't know yet.
     */
    protected function pullBalancer(string $instance, Server $server, Site $site): void
    {
        $domain = $site->name;

        $this->state->setSiteId($instance, $domain, $site->id);

        $nodes = collect($this->forge->balancingNodes($server->id, $site->id))->map(fn ($node) => [
            'server_id' => (int) $node->serverId,
            'weight' => $node->weight,
            'backup' => $node->backup,
            'port' => $node->port,
        ]);

        if ($nodes->isEmpty()) {
            $this->report("{$instance} {$domain}: Balancer has no nodes");

            return;
        }

        $pool = $this->state->pool($domain) ?? [];
        $known = fn ($node) => $this->state->instanceForServer($node['server_id']);

        $primaryNode = $nodes->first(fn ($node) => $known($node) && $known($node) === ($pool['primary'] ?? null)) ?? $nodes->first();
        $nodes = $nodes->sortBy(fn ($node) => $node === $primaryNode ? 0 : 1)->values();

        $names = $nodes->map(fn ($node) => $known($node) ?? Str::slug($this->forge->server($node['server_id'])->name));

        $this->state->putPool($domain, [
            'balancer' => $instance,
            'primary' => $names->first(),
            'nodes' => $names->toArray(),
            'options' => $nodes->mapWithKeys(fn ($node, $index) => [$names[$index] => [
                'weight' => $node['weight'] ?? Defaults::get('balancer.weight', 1),
                'backup' => (bool) ($node['backup'] ?? Defaults::get('balancer.backup', false)),
                'port' => $node['port'] ?? Defaults::get('balancer.port', 80),
            ]])->toArray(),
        ]);

        $this->report("{$instance} {$domain}: Balancing across ".$names->implode(', '));

        foreach ($nodes as $index => $node) {
            if (! $known($node)) {
                $this->server($node['server_id'], $names[$index]);
            }
        }
    }
}
