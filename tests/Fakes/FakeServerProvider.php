<?php

namespace Tests\Fakes;

use Grafite\Blacksmith\ServerProviders\ServerProvider;

class FakeServerProvider implements ServerProvider
{
    public array $created = [];

    public array $destroyed = [];

    public function name(): string
    {
        return 'fake';
    }

    public function create(string $name, array $options = []): int|string
    {
        $this->created[] = $name;

        return 9000 + count($this->created);
    }

    public function waitUntilActive(int|string $id, int $timeout = 600): array
    {
        return ['ip_address' => '203.0.113.'.($id - 9000), 'private_ip_address' => '10.10.0.'.($id - 9000)];
    }

    public function destroy(int|string $id): void
    {
        $this->destroyed[] = $id;
    }
}
