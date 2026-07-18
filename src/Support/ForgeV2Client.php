<?php

namespace Grafite\Blacksmith\Support;

use Laravel\Forge\Forge;
use RuntimeException;
use stdClass;

class ForgeV2Client
{
    protected Forge $forge;

    protected string $organization;

    public function __construct(?string $apiKey = null, ?string $organization = null)
    {
        $this->forge = new Forge($apiKey);
        $this->organization = $organization
            ?? config('blacksmith.forge_organization')
            ?? $this->resolveOrganization();
    }

    public function setTimeout(int $timeout): static
    {
        $this->forge->setTimeout($timeout);

        return $this;
    }

    public function createServer(array $data, bool $wait = true): mixed
    {
        return $this->forge->createServer($this->organization, $data, $wait);
    }

    public function updateServer(int $serverId, array $data): mixed
    {
        return $this->forge->updateServer($this->organization, $serverId, $data);
    }

    public function server(int $serverId): mixed
    {
        return $this->forge->server($this->organization, $serverId);
    }

    public function deleteServer(int $serverId): void
    {
        $this->forge->deleteServer($this->organization, $serverId);
    }

    public function sites(int $serverId): mixed
    {
        return $this->forge->serverSites($this->organization, $serverId);
    }

    public function site(int $serverId, int $siteId): mixed
    {
        return $this->forge->organizationSite($this->organization, $siteId);
    }

    public function createSite(int $serverId, array $data): mixed
    {
        return $this->forge->createSite($this->organization, $serverId, $data);
    }

    public function updateSite(int $serverId, int $siteId, array $data): void
    {
        $this->forge->updateSite($this->organization, $serverId, $siteId, $data);
    }

    public function deleteSite(int $serverId, int $siteId): void
    {
        $this->forge->deleteSite($this->organization, $serverId, $siteId);
    }

    public function createJob(int $serverId, array $data): mixed
    {
        return $this->forge->createScheduledJob($this->organization, $serverId, $data);
    }

    public function updateSiteGitRepository(int $serverId, int $siteId, array $repository): void
    {
        $this->forge->updateSite($this->organization, $serverId, $siteId, [
            'repository' => $repository['repository'] ?? null,
            'repository_provider' => $repository['provider'] ?? 'github',
            'repository_branch' => $repository['branch'] ?? 'main',
            'composer' => $repository['composer'] ?? false,
        ]);
    }

    public function installGitRepositoryOnSite(int $serverId, int $siteId, array $repository): void
    {
        $this->updateSiteGitRepository($serverId, $siteId, $repository);
    }

    public function updateSiteEnvironmentFile(int $serverId, int $siteId, string $content): void
    {
        $this->forge->updateSiteEnvironment($this->organization, $serverId, $siteId, $content);
    }

    public function siteEnvironmentFile(int $serverId, int $siteId): string
    {
        return $this->forge->siteEnvironment($this->organization, $serverId, $siteId);
    }

    public function updateSiteDeploymentScript(int $serverId, int $siteId, string $content): string
    {
        return $this->forge->updateDeploymentScript($this->organization, $serverId, $siteId, [
            'content' => $content,
        ]);
    }

    public function siteDeploymentScript(int $serverId, int $siteId): string
    {
        return $this->forge->deploymentScript($this->organization, $serverId, $siteId);
    }

    public function enableQuickDeploy(int $serverId, int $siteId): void
    {
        $this->forge->post(
            "orgs/{$this->organization}/servers/{$serverId}/sites/{$siteId}/deployments/status"
        );
    }

    public function securityRules(int $serverId, int $siteId): mixed
    {
        return $this->forge->securityRules($this->organization, $serverId, $siteId);
    }

    public function createSecurityRule(int $serverId, int $siteId, array $data): mixed
    {
        return $this->forge->createSecurityRule($this->organization, $serverId, $siteId, $data);
    }

    public function deleteSecurityRule(int $serverId, int $siteId, int $ruleId): void
    {
        $this->forge->deleteSecurityRule($this->organization, $serverId, $siteId, $ruleId);
    }

    public function redirectRules(int $serverId, int $siteId): mixed
    {
        return $this->forge->redirectRules($this->organization, $serverId, $siteId);
    }

    public function createRedirectRule(int $serverId, int $siteId, array $data): void
    {
        $this->forge->createRedirectRule($this->organization, $serverId, $siteId, $data);
    }

    public function deleteRedirectRule(int $serverId, int $siteId, int $ruleId): void
    {
        $this->forge->deleteRedirectRule($this->organization, $serverId, $siteId, $ruleId);
    }

    public function updateNodeBalancingConfiguration(int $serverId, int $siteId, array $data): void
    {
        $this->forge->updateLoadBalancingNodes($this->organization, $serverId, $siteId, $data);
    }

    public function workers(int $serverId, int $siteId): array
    {
        $response = $this->forge->get($this->normalizeUri("servers/{$serverId}/sites/{$siteId}/workers"));
        $workers = $response['data'] ?? [];

        return collect($workers)->map(function (array $worker) {
            $attributes = $worker['attributes'] ?? $worker;
            $id = $worker['id'] ?? ($attributes['id'] ?? null);
            $data = ['id' => $id] + $attributes;

            // Keep both flattened fields and an attributes array for existing legacy consumers:
            // - WorkersList reads $worker->attributes
            // - other commands read top-level fields like $worker->id and $worker->connection
            return (object) ($data + ['attributes' => $data]);
        })->all();
    }

    public function createWorker(int $serverId, int $siteId, array $data): mixed
    {
        $response = $this->forge->post($this->normalizeUri("servers/{$serverId}/sites/{$siteId}/workers"), $data);

        return $this->toObject($response['data'] ?? $response ?? []);
    }

    public function deleteWorker(int $serverId, int $siteId, int $workerId): void
    {
        $this->forge->delete($this->normalizeUri("servers/{$serverId}/sites/{$siteId}/workers/{$workerId}"));
    }

    public function restartWorker(int $serverId, int $siteId, int $workerId): mixed
    {
        return $this->forge->post($this->normalizeUri("servers/{$serverId}/sites/{$siteId}/workers/{$workerId}/restart"));
    }

    public function obtainLetsEncryptCertificate(int $serverId, int $siteId, array $data): mixed
    {
        return $this->forge->post($this->normalizeUri("servers/{$serverId}/sites/{$siteId}/certificates/letsencrypt"), $data);
    }

    public function get(string $uri, array $query = []): mixed
    {
        return $this->unwrapResponse($this->forge->get($this->normalizeUri($uri), $query));
    }

    public function post(string $uri, array $payload = []): mixed
    {
        return $this->forge->post($this->normalizeUri($uri), $payload);
    }

    public function delete(string $uri, array $payload = []): mixed
    {
        return $this->forge->delete($this->normalizeUri($uri), $payload);
    }

    protected function unwrapResponse(mixed $response): mixed
    {
        if (! is_array($response) || ! array_key_exists('data', $response)) {
            return $response;
        }

        $data = $response['data'];

        if (! is_array($data)) {
            return $response;
        }

        if (isset($data['attributes'])) {
            return ['id' => $data['id'] ?? null] + $data['attributes'];
        }

        return $data;
    }

    protected function toObject(array $data): stdClass
    {
        $attributes = $data['attributes'] ?? $data;
        $id = $data['id'] ?? ($attributes['id'] ?? null);

        return (object) (['id' => $id] + $attributes);
    }

    protected function normalizeUri(string $uri): string
    {
        $uri = ltrim($uri, '/');

        if (str_starts_with($uri, 'orgs/')) {
            return $uri;
        }

        if (str_starts_with($uri, 'servers/')) {
            return "orgs/{$this->organization}/{$uri}";
        }

        return $uri;
    }

    protected function resolveOrganization(): string
    {
        $organizations = $this->forge->organizations()->items();
        $organization = $organizations[0] ?? null;

        if (! $organization) {
            throw new RuntimeException('No Forge organization found. Set the forge_organization config value or BLACKSMITH_FORGE_ORGANIZATION environment variable to a valid organization slug from your Forge account.');
        }

        if (is_object($organization) && isset($organization->slug)) {
            return $organization->slug;
        }

        if (is_array($organization) && isset($organization['slug'])) {
            return $organization['slug'];
        }

        throw new RuntimeException('Unable to resolve Forge organization slug from the organizations response. Set the forge_organization config value or BLACKSMITH_FORGE_ORGANIZATION environment variable explicitly.');
    }
}
