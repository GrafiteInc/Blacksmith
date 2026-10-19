<?php

namespace Grafite\Blacksmith\ServerProviders;

use DigitalOceanV2\Client;
use DigitalOceanV2\Entity\Droplet;
use Grafite\Blacksmith\Support\Poll;
use RuntimeException;

class DigitalOceanProvider implements ServerProvider
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
