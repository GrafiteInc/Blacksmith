<?php

namespace Grafite\Blacksmith\Forge;

use Grafite\Blacksmith\Support\Defaults;
use Illuminate\Support\Str;
use Laravel\Forge\CursorPaginator;
use Laravel\Forge\Forge;
use Laravel\Forge\Resources\BackgroundProcess;
use Laravel\Forge\Resources\Deployment;
use Laravel\Forge\Resources\Server;
use Laravel\Forge\Resources\Site;
use RuntimeException;

/**
 * Blacksmith's only door to the Forge API (v2).
 *
 * Holds the organization every v2 route is scoped to, walks every page of a
 * list and smooths over the v1 actions that became several v2 calls.
 */
class ForgeGateway
{
    protected ?string $organization;

    public function __construct(protected Forge $forge, ?string $organization = null)
    {
        $this->organization = $organization ?: null;
    }

    public function sdk(): Forge
    {
        return $this->forge;
    }

    /**
     * The configured organization, or the token's only organization.
     */
    public function organization(): string
    {
        if ($this->organization) {
            return $this->organization;
        }

        $slugs = collect($this->all($this->forge->organizations()))->pluck('slug')->filter()->values();

        if ($slugs->count() !== 1) {
            throw new RuntimeException($slugs->isEmpty()
                ? 'Your Forge token has no organizations, check its scopes include organization:view.'
                : 'Your Forge token can reach several organizations ('.$slugs->implode(', ').'), set BLACKSMITH_FORGE_ORGANIZATION to one of them.');
        }

        return $this->organization = $slugs->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Servers
    |--------------------------------------------------------------------------
    */

    /**
     * @return Server[]
     */
    public function servers(): array
    {
        return $this->all($this->forge->servers($this->organization()));
    }

    public function server(int $serverId): Server
    {
        return $this->forge->server($this->organization(), $serverId);
    }

    /**
     * Connect an existing box as a custom server, the provision command still has to run on it.
     */
    public function createCustomServer(array $payload): Server
    {
        $payload['custom'] = array_filter([
            'ip_address' => $payload['ip_address'] ?? null,
            'private_ip_address' => $payload['private_ip_address'] ?? null,
        ]);

        unset($payload['ip_address'], $payload['private_ip_address']);

        return $this->forge->createServer($this->organization(), $payload, false);
    }

    public function deleteServer(int $serverId): void
    {
        $this->forge->deleteServer($this->organization(), $serverId);
    }

    public function phpVersions(int $serverId): array
    {
        return $this->all($this->forge->phpVersions($this->organization(), $serverId));
    }

    public function installPHP(int $serverId, string $version): void
    {
        $this->forge->installPhpVersion($this->organization(), $serverId, ['version' => $version]);
    }

    public function enableOPCache(int $serverId): void
    {
        $this->forge->createPhpOpcache($this->organization(), $serverId, []);
    }

    public function disableOPCache(int $serverId): void
    {
        $this->forge->deletePhpOpcache($this->organization(), $serverId);
    }

    public function updatePhpLimits(int $serverId, int $maxUploadSize, int $maxExecutionTime): void
    {
        $this->forge->updatePhpMaxUploadSize($this->organization(), $serverId, ['max_upload_size' => $maxUploadSize]);
        $this->forge->updatePhpMaxExecutionTime($this->organization(), $serverId, ['max_execution_time' => $maxExecutionTime]);
    }

    /*
    |--------------------------------------------------------------------------
    | Sites
    |--------------------------------------------------------------------------
    */

    /**
     * @return Site[]
     */
    public function sites(int $serverId): array
    {
        return $this->all($this->forge->serverSites($this->organization(), $serverId));
    }

    public function site(int $serverId, int $siteId): ?Site
    {
        return collect($this->sites($serverId))->firstWhere('id', $siteId);
    }

    public function createSite(int $serverId, array $payload): Site
    {
        return $this->forge->createSite($this->organization(), $serverId, $payload);
    }

    public function createBalancer(int $serverId, array $payload): Site
    {
        return $this->forge->createBalancer($this->organization(), $serverId, $payload);
    }

    public function updateSite(int $serverId, int $siteId, array $payload): void
    {
        $this->forge->updateSite($this->organization(), $serverId, $siteId, $payload);
    }

    public function deleteSite(int $serverId, int $siteId): void
    {
        $this->forge->deleteSite($this->organization(), $serverId, $siteId);
    }

    /**
     * Aliases are their own domain records in v2.
     */
    public function syncAliases(int $serverId, int $siteId, string $primary, array $aliases): void
    {
        $organization = $this->organization();
        $existing = collect($this->all($this->forge->domains($organization, $serverId, $siteId)))
            ->reject(fn ($domain) => $domain->name === $primary);

        foreach ($existing as $domain) {
            if (! in_array($domain->name, $aliases, true)) {
                $this->forge->deleteDomain($organization, $serverId, $siteId, $domain->id);
            }
        }

        foreach (array_diff($aliases, $existing->pluck('name')->toArray()) as $alias) {
            $this->forge->createDomain($organization, $serverId, $siteId, [
                'name' => $alias,
                'allow_wildcard_subdomains' => (bool) Defaults::get('domain.allow_wildcard_subdomains', false),
                'www_redirect_type' => Defaults::get('domain.www_redirect_type', 'none'),
            ]);
        }
    }

    public function siteEnvironmentFile(int $serverId, int $siteId): string
    {
        return $this->forge->siteEnvironment($this->organization(), $serverId, $siteId);
    }

    public function updateSiteEnvironmentFile(int $serverId, int $siteId, string $contents): void
    {
        $this->forge->updateSiteEnvironment($this->organization(), $serverId, $siteId, $contents);
    }

    public function siteDeploymentScript(int $serverId, int $siteId): string
    {
        return $this->forge->deploymentScript($this->organization(), $serverId, $siteId);
    }

    public function updateSiteDeploymentScript(int $serverId, int $siteId, string $contents): void
    {
        $this->forge->updateDeploymentScript($this->organization(), $serverId, $siteId, ['content' => $contents]);
    }

    public function deploySite(int $serverId, int $siteId): Deployment
    {
        return $this->forge->createDeployment($this->organization(), $serverId, $siteId);
    }

    public function enableQuickDeploy(int $serverId, int $siteId): void
    {
        $this->forge->enablePushToDeploy($this->organization(), $serverId, $siteId, []);
    }

    public function disableQuickDeploy(int $serverId, int $siteId): void
    {
        $this->forge->disablePushToDeploy($this->organization(), $serverId, $siteId);
    }

    /**
     * Request a Let's Encrypt certificate on each of the site's domain records.
     */
    public function obtainLetsEncryptCertificate(int $serverId, int $siteId, array $domains): void
    {
        $organization = $this->organization();
        $records = collect($this->all($this->forge->domains($organization, $serverId, $siteId)))
            ->filter(fn ($record) => in_array($record->name, $domains, true));

        if ($records->isEmpty()) {
            throw new RuntimeException('None of '.implode(', ', $domains).' are domains of the site, add them as aliases first.');
        }

        foreach ($records as $record) {
            $this->forge->createCertificate($organization, $serverId, $siteId, $record->id, [
                'type' => 'letsencrypt',
                'letsencrypt' => ['verification_method' => Defaults::get('certificate.verification_method', 'http-01')],
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Scheduled jobs
    |--------------------------------------------------------------------------
    */

    public function scheduledJobs(int $serverId): array
    {
        return $this->all($this->forge->scheduledJobs($this->organization(), $serverId));
    }

    public function createScheduledJob(int $serverId, array $payload): void
    {
        $this->forge->createScheduledJob($this->organization(), $serverId, $payload);
    }

    public function deleteScheduledJob(int $serverId, int $jobId): void
    {
        $this->forge->deleteScheduledJob($this->organization(), $serverId, $jobId);
    }

    /*
    |--------------------------------------------------------------------------
    | Background processes (queue workers)
    |--------------------------------------------------------------------------
    */

    /**
     * @return BackgroundProcess[]
     */
    public function backgroundProcesses(int $serverId): array
    {
        return $this->all($this->forge->backgroundProcesses($this->organization(), $serverId));
    }

    public function createBackgroundProcess(int $serverId, array $payload): BackgroundProcess
    {
        return $this->forge->createBackgroundProcess($this->organization(), $serverId, $payload);
    }

    public function deleteBackgroundProcess(int $serverId, int $processId): void
    {
        $this->forge->deleteBackgroundProcess($this->organization(), $serverId, $processId);
    }

    public function restartBackgroundProcess(int $serverId, int $processId): void
    {
        $this->forge->performBackgroundProcessAction($this->organization(), $serverId, $processId, ['action' => 'restart']);
    }

    /*
    |--------------------------------------------------------------------------
    | Redirect and security rules
    |--------------------------------------------------------------------------
    */

    public function redirectRules(int $serverId, int $siteId): array
    {
        return $this->all($this->forge->redirectRules($this->organization(), $serverId, $siteId));
    }

    public function createRedirectRule(int $serverId, int $siteId, array $rule): void
    {
        $this->forge->createRedirectRule($this->organization(), $serverId, $siteId, $rule);
    }

    public function deleteRedirectRule(int $serverId, int $siteId, int $ruleId): void
    {
        $this->forge->deleteRedirectRule($this->organization(), $serverId, $siteId, $ruleId);
    }

    public function securityRules(int $serverId, int $siteId): array
    {
        return $this->all($this->forge->securityRules($this->organization(), $serverId, $siteId));
    }

    public function createSecurityRule(int $serverId, int $siteId, array $rule): void
    {
        $this->forge->createSecurityRule($this->organization(), $serverId, $siteId, $rule);
    }

    public function deleteSecurityRule(int $serverId, int $siteId, int $ruleId): void
    {
        $this->forge->deleteSecurityRule($this->organization(), $serverId, $siteId, $ruleId);
    }

    /*
    |--------------------------------------------------------------------------
    | Load balancing
    |--------------------------------------------------------------------------
    */

    public function balancingNodes(int $serverId, int $siteId): array
    {
        return $this->all($this->forge->loadBalancingNodes($this->organization(), $serverId, $siteId));
    }

    /**
     * @param  array  $nodes  each with server_id, weight, port, backup and down
     */
    public function updateBalancingNodes(int $serverId, int $siteId, array $nodes, string $method): void
    {
        $this->forge->updateLoadBalancingNodes($this->organization(), $serverId, $siteId, [
            'balancer_method' => $method,
            'balancing' => $nodes,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * The directory the site's code runs from.
     */
    public static function siteRoot(Site $site): string
    {
        $root = rtrim($site->rootDirectory ?: '/home/'.($site->user ?: Defaults::user()).'/'.$site->name, '/');

        return $site->zeroDowntimeDeployments && ! Str::endsWith($root, '/current') ? $root.'/current' : $root;
    }

    /**
     * The site's repository as owner/name, provider and branch.
     *
     * @return array{provider: ?string, repository: string, branch: ?string}|null
     */
    public static function siteRepository(Site $site): ?array
    {
        $repository = $site->repository;

        if (is_string($repository)) {
            $repository = ['url' => $repository];
        }

        if (! is_array($repository) || empty($repository['url'] ?? $repository['repository'] ?? $repository['name'] ?? null)) {
            return null;
        }

        $url = (string) ($repository['url'] ?? $repository['repository'] ?? $repository['name']);
        $name = preg_replace('#^(?:[a-z+]+://)?(?:[^@/]+@)?[^/:]+[:/](?=[^/]+/[^/]+$)#i', '', $url);

        return [
            'provider' => $repository['provider'] ?? null,
            'repository' => preg_replace('/\.git$/', '', trim($name, '/')),
            'branch' => $repository['branch'] ?? null,
        ];
    }

    protected function all(CursorPaginator|array $items): array
    {
        return is_array($items) ? $items : iterator_to_array($items->lazy(), false);
    }
}
