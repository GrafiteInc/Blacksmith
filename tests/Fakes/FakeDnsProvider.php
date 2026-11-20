<?php

namespace Tests\Fakes;

use Grafite\Blacksmith\Dns\DnsProvider;

class FakeDnsProvider implements DnsProvider
{
    /**
     * Every write, in order, as "create host ip" or "update host id ip".
     */
    public array $calls = [];

    /**
     * @param  array  $records  hostname => [['type' => 'A', 'content' => '10.0.0.1', ...]]
     * @param  array  $zones  domain => zone ID
     */
    public function __construct(public array $records = [], public array $zones = []) {}

    public function name(): string
    {
        return 'fake';
    }

    public function zoneFor(string $hostname): ?string
    {
        foreach ($this->zones as $domain => $zone) {
            if ($hostname === $domain || str_ends_with($hostname, '.'.$domain)) {
                return $zone;
            }
        }

        return null;
    }

    public function records(string $hostname): array
    {
        return collect($this->records[$hostname] ?? [])
            ->map(fn ($record, $index) => array_replace([
                'id' => "{$hostname}-{$index}",
                'type' => 'A',
                'name' => $hostname,
                'content' => '',
                'proxied' => true,
                'ttl' => 1,
            ], $record))
            ->values()
            ->toArray();
    }

    public function createARecord(string $hostname, string $ip): void
    {
        $this->calls[] = "create {$hostname} {$ip}";
        $this->records[$hostname][] = ['type' => 'A', 'content' => $ip];
    }

    public function updateARecord(string $hostname, string $recordId, string $ip): void
    {
        $this->calls[] = "update {$hostname} {$recordId} {$ip}";

        foreach ($this->records($hostname) as $index => $record) {
            if ($record['id'] === $recordId) {
                $this->records[$hostname][$index]['content'] = $ip;
            }
        }
    }

    /**
     * The A record addresses for a hostname.
     */
    public function addresses(string $hostname): array
    {
        return collect($this->records($hostname))->where('type', 'A')->pluck('content')->values()->toArray();
    }
}
