<?php

namespace Grafite\Blacksmith\Dns;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

/**
 * Cloudflare's v4 API, the token needs Zone:Read and DNS:Edit.
 */
class CloudflareDnsProvider implements DnsProvider
{
    public const BASE_URI = 'https://api.cloudflare.com/client/v4/';

    /**
     * hostname => zone ID, or null when no zone matched.
     */
    protected array $zones = [];

    /**
     * @param  array  $knownZones  domain => zone ID, checked before asking Cloudflare
     */
    public function __construct(
        protected ?string $token,
        protected bool $proxied = true,
        protected array $knownZones = [],
        protected ?ClientInterface $client = null,
    ) {}

    public function name(): string
    {
        return 'cloudflare';
    }

    public function zoneFor(string $hostname): ?string
    {
        $hostname = static::normalize($hostname);

        if (array_key_exists($hostname, $this->zones)) {
            return $this->zones[$hostname];
        }

        foreach (static::candidates($hostname) as $candidate) {
            if (! empty($this->knownZones[$candidate])) {
                return $this->zones[$hostname] = $this->knownZones[$candidate];
            }
        }

        foreach (static::candidates($hostname) as $candidate) {
            $zone = $this->request('GET', 'zones', ['query' => ['name' => $candidate]])[0] ?? null;

            if ($zone) {
                return $this->zones[$hostname] = $zone['id'];
            }
        }

        return $this->zones[$hostname] = null;
    }

    public function records(string $hostname): array
    {
        $hostname = static::normalize($hostname);

        return collect($this->request('GET', "zones/{$this->zone($hostname)}/dns_records", ['query' => ['name' => $hostname, 'per_page' => 100]]))
            ->filter(fn ($record) => in_array($record['type'] ?? null, ['A', 'AAAA', 'CNAME'], true))
            ->map(fn ($record) => [
                'id' => (string) $record['id'],
                'type' => $record['type'],
                'name' => $record['name'],
                'content' => $record['content'],
                'proxied' => $record['proxied'] ?? null,
                'ttl' => $record['ttl'] ?? null,
            ])
            ->values()
            ->toArray();
    }

    public function createARecord(string $hostname, string $ip): void
    {
        $hostname = static::normalize($hostname);

        $this->request('POST', "zones/{$this->zone($hostname)}/dns_records", ['json' => [
            'type' => 'A',
            'name' => $hostname,
            'content' => $ip,
            'proxied' => $this->proxied,
            'ttl' => 1,
        ]]);
    }

    public function updateARecord(string $hostname, string $recordId, string $ip): void
    {
        $hostname = static::normalize($hostname);

        $this->request('PATCH', "zones/{$this->zone($hostname)}/dns_records/{$recordId}", ['json' => ['content' => $ip]]);
    }

    /**
     * www.app.example.com, app.example.com, example.com, the zone is the longest one that exists.
     */
    public static function candidates(string $hostname): array
    {
        $labels = explode('.', ltrim(static::normalize($hostname), '*.'));
        $candidates = [];

        for ($i = 0; $i < count($labels) - 1; $i++) {
            $candidates[] = implode('.', array_slice($labels, $i));
        }

        return $candidates;
    }

    protected static function normalize(string $hostname): string
    {
        return strtolower(rtrim(trim($hostname), '.'));
    }

    protected function zone(string $hostname): string
    {
        return $this->zoneFor($hostname) ?? throw new RuntimeException("No Cloudflare zone found for {$hostname}, check the token can read it.");
    }

    protected function request(string $method, string $uri, array $options = []): array
    {
        try {
            $response = $this->client()->request($method, $uri, $options + ['http_errors' => false]);
        } catch (RequestException $exception) {
            $response = $exception->getResponse() ?? throw new RuntimeException('Cloudflare: '.$exception->getMessage(), 0, $exception);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Cloudflare: '.$exception->getMessage(), 0, $exception);
        }

        $body = json_decode((string) $response->getBody(), true);

        if (! is_array($body) || empty($body['success'])) {
            $errors = collect($body['errors'] ?? [])->map(fn ($error) => trim(($error['code'] ?? '').' '.($error['message'] ?? '')))->filter()->implode(', ');

            throw new RuntimeException("Cloudflare {$method} {$uri} failed: ".($errors ?: 'HTTP '.$response->getStatusCode()));
        }

        return (array) ($body['result'] ?? []);
    }

    protected function client(): ClientInterface
    {
        if (is_null($this->client)) {
            if (empty($this->token)) {
                throw new RuntimeException('Set BLACKSMITH_CLOUDFLARE_TOKEN to manage DNS with Cloudflare.');
            }

            $this->client = new Client([
                'base_uri' => static::BASE_URI,
                'timeout' => 30,
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                    'Accept' => 'application/json',
                ],
            ]);
        }

        return $this->client;
    }
}
