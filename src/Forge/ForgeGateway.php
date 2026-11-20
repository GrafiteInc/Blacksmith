<?php

namespace Grafite\Blacksmith\Forge;

use Closure;
use Grafite\Blacksmith\Support\Defaults;
use Grafite\Blacksmith\Support\Poll;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Laravel\Forge\CursorPaginator;
use Laravel\Forge\Exceptions\FailedActionException;
use Laravel\Forge\Exceptions\ValidationException;
use Laravel\Forge\Forge;
use Laravel\Forge\Resources\BackgroundProcess;
use Laravel\Forge\Resources\Deployment;
use Laravel\Forge\Resources\Recipe;
use Laravel\Forge\Resources\RecipeRun;
use Laravel\Forge\Resources\Server;
use Laravel\Forge\Resources\ServerCredential;
use Laravel\Forge\Resources\Site;
use Laravel\Forge\Resources\VPC;
use RuntimeException;

/**
 * Blacksmith's only door to the Forge API (v2).
 *
 * Holds the organization every v2 route is scoped to, walks every page of a
 * list and smooths over the v1 actions that became several v2 calls.
 */
class ForgeGateway
{
    /**
     * Deployment statuses that end a deployment without it going live.
     */
    public const DEPLOYMENT_FAILED = ['failed', 'failed-build', 'cancelled'];

    public const DEPLOYMENT_FINISHED = 'finished';

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
     * Create a server, Forge provisions it on the provider named in the payload. Returns without waiting.
     */
    public function createServer(array $payload): Server
    {
        return $this->send("creating server {$payload['name']}", fn () => $this->forge->createServer($this->organization(), $payload, false));
    }

    /**
     * The organization's server credentials, the provider accounts Forge can create servers with.
     *
     * @return ServerCredential[]
     */
    public function serverCredentials(): array
    {
        return $this->all($this->forge->serverCredentials($this->organization()));
    }

    /**
     * @return VPC[]
     */
    public function vpcs(int $credentialId, string $region): array
    {
        return $this->all($this->forge->vpcs($this->organization(), $credentialId, $region));
    }

    /**
     * Connect an existing box as a custom server, the provision command still has to run on it.
     *
     * Forge requires the SSH port, it defaults to blacksmith.ssh.port.
     */
    public function createCustomServer(array $payload): Server
    {
        $payload['custom'] = array_filter([
            'ip_address' => $payload['ip_address'] ?? null,
            'private_ip_address' => $payload['private_ip_address'] ?? null,
            'ssh_port' => (int) ($payload['ssh_port'] ?? config('blacksmith.ssh.port', 22)),
        ]);

        unset($payload['ip_address'], $payload['private_ip_address'], $payload['ssh_port']);

        return $this->send("creating server {$payload['name']}", fn () => $this->forge->createServer($this->organization(), $payload, false));
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
        $this->send("installing {$version} on server {$serverId}", fn () => $this->forge->installPhpVersion($this->organization(), $serverId, ['version' => $version]));
    }

    public function enableOPCache(int $serverId): void
    {
        $this->send("enabling OPcache on server {$serverId}", fn () => $this->forge->createPhpOpcache($this->organization(), $serverId, []));
    }

    public function disableOPCache(int $serverId): void
    {
        $this->send("disabling OPcache on server {$serverId}", fn () => $this->forge->deletePhpOpcache($this->organization(), $serverId));
    }

    public function updatePhpLimits(int $serverId, int $maxUploadSize, int $maxExecutionTime): void
    {
        $this->send("the max upload size on server {$serverId}", fn () => $this->forge->updatePhpMaxUploadSize($this->organization(), $serverId, ['max_upload_size' => $maxUploadSize]));
        $this->send("the max execution time on server {$serverId}", fn () => $this->forge->updatePhpMaxExecutionTime($this->organization(), $serverId, ['max_execution_time' => $maxExecutionTime]));
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
        return $this->send('creating site '.($payload['domain'] ?? $payload['name'] ?? '')." on server {$serverId}", fn () => $this->forge->createSite($this->organization(), $serverId, $payload));
    }

    public function createBalancer(int $serverId, array $payload): Site
    {
        return $this->send('creating balancer '.($payload['domain'] ?? $payload['name'] ?? '')." on server {$serverId}", fn () => $this->forge->createBalancer($this->organization(), $serverId, $payload));
    }

    public function updateSite(int $serverId, int $siteId, array $payload): void
    {
        $this->send("updating site {$siteId}", fn () => $this->forge->updateSite($this->organization(), $serverId, $siteId, $payload));
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
            $this->send("adding alias {$alias} to site {$siteId}", fn () => $this->forge->createDomain($organization, $serverId, $siteId, [
                'name' => $alias,
                'allow_wildcard_subdomains' => (bool) Defaults::get('domain.allow_wildcard_subdomains', false),
                'www_redirect_type' => Defaults::get('domain.www_redirect_type', 'none'),
            ]));
        }
    }

    public function siteEnvironmentFile(int $serverId, int $siteId): string
    {
        return $this->forge->siteEnvironment($this->organization(), $serverId, $siteId);
    }

    public function updateSiteEnvironmentFile(int $serverId, int $siteId, string $contents): void
    {
        $this->send("the env of site {$siteId}", fn () => $this->forge->updateSiteEnvironment($this->organization(), $serverId, $siteId, $contents));
    }

    public function siteDeploymentScript(int $serverId, int $siteId): string
    {
        return $this->forge->deploymentScript($this->organization(), $serverId, $siteId);
    }

    public function updateSiteDeploymentScript(int $serverId, int $siteId, string $contents): void
    {
        $this->send("the deploy script of site {$siteId}", fn () => $this->forge->updateDeploymentScript($this->organization(), $serverId, $siteId, ['content' => $contents]));
    }

    public function deploySite(int $serverId, int $siteId): Deployment
    {
        return $this->send("deploying site {$siteId}", fn () => $this->forge->createDeployment($this->organization(), $serverId, $siteId));
    }

    public function deployment(int $serverId, int $siteId, int $deploymentId): Deployment
    {
        return $this->forge->deployment($this->organization(), $serverId, $siteId, $deploymentId);
    }

    /**
     * The site's most recent deployment, from the first page Forge returns.
     */
    public function latestDeployment(int $serverId, int $siteId): ?Deployment
    {
        return collect($this->forge->deployments($this->organization(), $serverId, $siteId)->items())
            ->sortByDesc('id')
            ->first();
    }

    public function deploymentLog(int $serverId, int $siteId, int $deploymentId): string
    {
        return $this->forge->deploymentLog($this->organization(), $serverId, $siteId, $deploymentId);
    }

    /**
     * Deploy the site and wait until the deployment is live, so nothing is pointed at code that isn't there yet.
     */
    public function deployAndWait(int $serverId, int $siteId, ?int $timeout = null): Deployment
    {
        return $this->waitForDeployment($serverId, $siteId, $this->deploySite($serverId, $siteId), $timeout);
    }

    /**
     * Poll a deployment until it finishes, a failed or timed out deployment throws with the end of its log.
     */
    public function waitForDeployment(int $serverId, int $siteId, Deployment $deployment, ?int $timeout = null): Deployment
    {
        if (! $deployment->id) {
            throw new RuntimeException('Forge did not return the deployment, check it in Forge.');
        }

        $timeout ??= (int) config('blacksmith.deploy_timeout', 900);
        $latest = $deployment;

        $done = Poll::until(function () use ($serverId, $siteId, &$latest) {
            $latest = $this->deployment($serverId, $siteId, $latest->id);

            return $latest->status === static::DEPLOYMENT_FINISHED || in_array($latest->status, static::DEPLOYMENT_FAILED, true);
        }, $timeout, (int) config('blacksmith.poll_interval', 15));

        if ($done && $latest->status === static::DEPLOYMENT_FINISHED) {
            return $latest;
        }

        $reason = $done ? "ended as {$latest->status}" : "was still {$latest->status} after {$timeout} seconds";

        throw new RuntimeException("Deployment {$latest->id} {$reason}.".$this->logTail($serverId, $siteId, $latest->id));
    }

    protected function logTail(int $serverId, int $siteId, int $deploymentId, int $lines = 15): string
    {
        try {
            $log = trim($this->deploymentLog($serverId, $siteId, $deploymentId));
        } catch (\Throwable) {
            return '';
        }

        return $log === '' ? '' : PHP_EOL.implode(PHP_EOL, array_slice(explode(PHP_EOL, $log), -$lines));
    }

    public function enableQuickDeploy(int $serverId, int $siteId): void
    {
        $this->send("enabling quick deploy on site {$siteId}", fn () => $this->forge->enablePushToDeploy($this->organization(), $serverId, $siteId, []));
    }

    public function disableQuickDeploy(int $serverId, int $siteId): void
    {
        $this->send("disabling quick deploy on site {$siteId}", fn () => $this->forge->disablePushToDeploy($this->organization(), $serverId, $siteId));
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
            $this->send("a certificate for {$record->name}", fn () => $this->forge->createCertificate($organization, $serverId, $siteId, $record->id, [
                'type' => 'letsencrypt',
                'letsencrypt' => ['verification_method' => Defaults::get('certificate.verification_method', 'http-01')],
            ]));
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
        $this->send("creating a scheduled job on server {$serverId}", fn () => $this->forge->createScheduledJob($this->organization(), $serverId, $payload));
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
        return $this->send("creating a background process on server {$serverId}", fn () => $this->forge->createBackgroundProcess($this->organization(), $serverId, $payload));
    }

    public function deleteBackgroundProcess(int $serverId, int $processId): void
    {
        $this->forge->deleteBackgroundProcess($this->organization(), $serverId, $processId);
    }

    public function restartBackgroundProcess(int $serverId, int $processId): void
    {
        $this->send("restarting background process {$processId}", fn () => $this->forge->performBackgroundProcessAction($this->organization(), $serverId, $processId, ['action' => 'restart']));
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
        $this->send("a redirect rule on site {$siteId}", fn () => $this->forge->createRedirectRule($this->organization(), $serverId, $siteId, $rule));
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
        $this->send("a security rule on site {$siteId}", fn () => $this->forge->createSecurityRule($this->organization(), $serverId, $siteId, $rule));
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
        $this->send("the balancer nodes of site {$siteId}", fn () => $this->forge->updateLoadBalancingNodes($this->organization(), $serverId, $siteId, [
            'balancer_method' => $method,
            'balancing' => $nodes,
        ]));
    }

    /*
    |--------------------------------------------------------------------------
    | Recipes
    |--------------------------------------------------------------------------
    */

    public function createRecipe(string $name, string $user, string $script): Recipe
    {
        return $this->send("creating recipe {$name}", fn () => $this->forge->createRecipe($this->organization(), [
            'name' => $name,
            'user' => $user,
            'script' => $script,
        ]));
    }

    /**
     * Run a recipe on one server, Forge runs it in the background.
     */
    public function runRecipe(int $recipeId, int $serverId): void
    {
        $this->send("running recipe {$recipeId} on server {$serverId}", fn () => $this->forge->createRecipeRun($this->organization(), $recipeId, [
            'servers' => [$serverId],
            'notify' => false,
        ]));
    }

    /**
     * @return RecipeRun[]
     */
    public function recipeRuns(int $recipeId): array
    {
        return $this->all($this->forge->recipeRuns($this->organization(), $recipeId));
    }

    public function deleteRecipe(int $recipeId): void
    {
        $this->forge->deleteRecipe($this->organization(), $recipeId);
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

    /**
     * Run a Forge write, turning its rejections into errors the commands report, with Forge's reasons.
     *
     * The SDK's ValidationException (422) and FailedActionException (400) are not RuntimeExceptions,
     * so without this they skip every command's error handling and lose the fields Forge named.
     */
    protected function send(string $action, Closure $call): mixed
    {
        try {
            return $call();
        } catch (ValidationException $exception) {
            throw new RuntimeException("Forge rejected {$action}: ".static::reasons($exception->errors()), 422, $exception);
        } catch (FailedActionException $exception) {
            $body = json_decode($exception->getMessage(), true);

            throw new RuntimeException("Forge rejected {$action}: ".(is_array($body) ? static::reasons($body) : $exception->getMessage()), 400, $exception);
        }
    }

    /**
     * "field: message" for each error in a Forge error body, or its message when it names no fields.
     */
    protected static function reasons(array $body): string
    {
        $errors = collect(is_array($body['errors'] ?? null) ? $body['errors'] : [])
            ->map(fn ($messages, $field) => (is_string($field) ? "{$field}: " : '').implode(' ', Arr::flatten((array) $messages)))
            ->filter()
            ->values();

        if ($errors->isNotEmpty()) {
            return $errors->implode('; ');
        }

        return is_string($body['message'] ?? null) && $body['message'] !== '' ? $body['message'] : json_encode($body);
    }

    protected function all(CursorPaginator|array $items): array
    {
        return is_array($items) ? $items : iterator_to_array($items->lazy(), false);
    }
}
