<?php

namespace Grafite\Blacksmith\Dns;

interface DnsProvider
{
    /**
     * The provider's name, used in messages.
     */
    public function name(): string;

    /**
     * The ID of the zone the hostname lives in, null when the account has no zone for it.
     */
    public function zoneFor(string $hostname): ?string;

    /**
     * The A, AAAA and CNAME records for exactly this hostname.
     *
     * @return array<int, array{id: string, type: string, name: string, content: string, proxied: ?bool, ttl: ?int}>
     */
    public function records(string $hostname): array;

    public function createARecord(string $hostname, string $ip): void;

    /**
     * Change only the record's address, its proxy and TTL settings are kept.
     */
    public function updateARecord(string $hostname, string $recordId, string $ip): void;
}
