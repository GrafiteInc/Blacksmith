<?php

namespace Grafite\Blacksmith\ServerProviders;

interface ServerProvider
{
    /**
     * The key stored in state so the server can be destroyed later.
     */
    public function name(): string;

    /**
     * Create a server and return the provider's ID for it.
     */
    public function create(string $name, array $options = []): int|string;

    /**
     * Wait for the server to boot and return its addresses.
     *
     * @return array{ip_address: string, private_ip_address: ?string}
     */
    public function waitUntilActive(int|string $id, int $timeout = 600): array;

    public function destroy(int|string $id): void;
}
