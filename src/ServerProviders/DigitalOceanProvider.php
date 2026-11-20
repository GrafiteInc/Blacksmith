<?php

namespace Grafite\Blacksmith\ServerProviders;

use DigitalOceanV2\Client;
use DigitalOceanV2\Entity\Droplet;
use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\Support\Poll;
use RuntimeException;

class DigitalOceanProvider implements ForgeManagedProvider, ServerProvider
{
    protected ?Client $client = null;

    public function __construct(
        protected ?string $token,
        protected array $defaults = [],
        protected int $interval = 15,
    ) {}

    public function name(): string
    {
        return 'digitalocean';
    }

    /**
     * Forge's provider slug for DigitalOcean.
     */
    public const FORGE_PROVIDER = 'ocean2';

    /**
     * Forge creates the droplet with the organization's DigitalOcean credential, in the region's VPC.
     */
    public function forgePayload(ForgeGateway $forge, array $options = []): array
    {
        $options = array_replace($this->defaults, $options);
        $credentialId = $this->credentialId($forge, $options);

        return [
            'provider' => static::FORGE_PROVIDER,
            'credential_id' => $credentialId,
            static::FORGE_PROVIDER => [
                'region_id' => $options['region'],
                'size_id' => $options['size'],
                'vpc_uuid' => $options['vpc_uuid'] ?: $this->vpcUuid($forge, $credentialId, $options['region']),
                'enable_weekly_backups' => (bool) ($options['backups'] ?? false),
            ],
        ];
    }

    /**
     * The configured credential, or the organization's only DigitalOcean one.
     */
    protected function credentialId(ForgeGateway $forge, array $options): int
    {
        if ($configured = $options['credential_id'] ?? config('blacksmith.forge_credential')) {
            return (int) $configured;
        }

        $all = collect($forge->serverCredentials());
        $credentials = $all->filter(fn ($credential) => in_array(strtolower(str_replace(' ', '', (string) $credential->provider)), ['digitalocean', static::FORGE_PROVIDER], true))->values();

        if ($credentials->count() === 1) {
            return (int) $credentials->first()->id;
        }

        throw new RuntimeException($credentials->isEmpty()
            ? 'Your Forge organization has no DigitalOcean server credential, add one in Forge first.'
            : 'Your Forge organization has several DigitalOcean credentials ('.$credentials->map(fn ($credential) => "{$credential->id} {$credential->name}")->implode(', ').'), set BLACKSMITH_FORGE_CREDENTIAL to one of them.');
    }

    /**
     * Forge requires a VPC: the region's only one, or its default-{region} VPC.
     */
    protected function vpcUuid(ForgeGateway $forge, int $credentialId, string $region): string
    {
        $vpcs = collect($forge->vpcs($credentialId, $region));
        $vpc = $vpcs->count() === 1 ? $vpcs->first() : $vpcs->firstWhere('name', "default-{$region}");

        if (! $vpc) {
            throw new RuntimeException($vpcs->isEmpty()
                ? "DigitalOcean has no VPC in {$region} for Forge credential {$credentialId}, create one or set BLACKSMITH_DO_VPC_UUID."
                : "{$region} has several VPCs (".$vpcs->pluck('name')->implode(', ').'), set BLACKSMITH_DO_VPC_UUID to one of them.');
        }

        return (string) $vpc->id;
    }

    public function create(string $name, array $options = []): int|string
    {
        $options = array_replace($this->defaults, $options);

        $sshKeys = collect($this->client()->key()->getAll())->pluck('id')->toArray();

        /** @var Droplet $droplet */
        $droplet = $this->client()->droplet()->create(
            $name,
            $options['region'],
            $options['size'],
            $options['image'],
            (bool) ($options['backups'] ?? false),
            (bool) ($options['ipv6'] ?? false),
            $options['vpc_uuid'] ?? false,
            $sshKeys,
            '',
            (bool) ($options['monitoring'] ?? true),
            [],
            ['blacksmith'],
        );

        return $droplet->id;
    }

    public function waitUntilActive(int|string $id, int $timeout = 600): array
    {
        $addresses = Poll::until(function () use ($id) {
            $droplet = $this->client()->droplet()->getById((int) $id);

            if ($droplet->status === 'active' && $ip = $this->address($droplet, 'public')) {
                return [
                    'ip_address' => $ip,
                    'private_ip_address' => $this->address($droplet, 'private'),
                ];
            }

            return null;
        }, $timeout, $this->interval);

        if (! $addresses) {
            throw new RuntimeException("Droplet {$id} did not become active within {$timeout} seconds.");
        }

        return $addresses;
    }

    public function destroy(int|string $id): void
    {
        $this->client()->droplet()->remove((int) $id);
    }

    protected function address(Droplet $droplet, string $type): ?string
    {
        foreach ($droplet->networks as $network) {
            if ($network->version === 4 && $network->type === $type) {
                return $network->ipAddress;
            }
        }

        return null;
    }

    protected function client(): Client
    {
        if (is_null($this->client)) {
            if (empty($this->token)) {
                throw new RuntimeException('Set BLACKSMITH_SERVER_PROVIDER_TOKEN to create DigitalOcean servers.');
            }

            $this->client = new Client;
            $this->client->authenticate($this->token);
        }

        return $this->client;
    }
}
