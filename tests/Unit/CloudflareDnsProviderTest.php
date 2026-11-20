<?php

namespace Tests\Unit;

use Grafite\Blacksmith\Dns\CloudflareDnsProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CloudflareDnsProviderTest extends TestCase
{
    protected array $history = [];

    protected function provider(array $responses, array $zones = [], bool $proxied = true): CloudflareDnsProvider
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new CloudflareDnsProvider('token', $proxied, $zones, new Client([
            'handler' => $stack,
            'base_uri' => CloudflareDnsProvider::BASE_URI,
        ]));
    }

    protected function ok(array $result = []): Response
    {
        return new Response(200, [], json_encode(['success' => true, 'errors' => [], 'result' => $result]));
    }

    protected function sent(int $index): array
    {
        $request = $this->history[$index]['request'];
        parse_str($request->getUri()->getQuery(), $query);

        return [
            'method' => $request->getMethod(),
            'path' => $request->getUri()->getPath(),
            'query' => $query,
            'body' => json_decode((string) $request->getBody(), true),
        ];
    }

    public function test_the_zone_is_the_longest_name_cloudflare_has()
    {
        $dns = $this->provider([$this->ok(), $this->ok([['id' => 'zone-1', 'name' => 'example.com']])]);

        $this->assertSame('zone-1', $dns->zoneFor('WWW.Example.com.'));
        $this->assertSame('zone-1', $dns->zoneFor('www.example.com'));

        $this->assertCount(2, $this->history);
        $this->assertSame(['name' => 'www.example.com'], $this->sent(0)['query']);
        $this->assertSame(['name' => 'example.com'], $this->sent(1)['query']);
    }

    public function test_known_zones_skip_the_lookup()
    {
        $dns = $this->provider([], ['example.com' => 'zone-9']);

        $this->assertSame('zone-9', $dns->zoneFor('api.example.com'));
        $this->assertCount(0, $this->history);
    }

    public function test_a_missing_zone_is_null()
    {
        $dns = $this->provider([$this->ok(), $this->ok()]);

        $this->assertNull($dns->zoneFor('www.example.org'));
        $this->assertSame(['www.example.org', 'example.org'], CloudflareDnsProvider::candidates('*.www.example.org'));
    }

    public function test_records_are_filtered_to_addresses_and_cnames()
    {
        $dns = $this->provider([$this->ok([
            ['id' => 'a1', 'type' => 'A', 'name' => 'example.com', 'content' => '10.0.0.1', 'proxied' => true, 'ttl' => 1],
            ['id' => 't1', 'type' => 'TXT', 'name' => 'example.com', 'content' => 'v=spf1'],
            ['id' => 'm1', 'type' => 'MX', 'name' => 'example.com', 'content' => 'mail.example.com'],
        ])], ['example.com' => 'zone-1']);

        $records = $dns->records('example.com');

        $this->assertSame([['id' => 'a1', 'type' => 'A', 'name' => 'example.com', 'content' => '10.0.0.1', 'proxied' => true, 'ttl' => 1]], $records);
        $this->assertSame('/client/v4/zones/zone-1/dns_records', $this->sent(0)['path']);
        $this->assertSame('example.com', $this->sent(0)['query']['name']);
    }

    public function test_an_update_only_changes_the_address()
    {
        $dns = $this->provider([$this->ok(['id' => 'a1'])], ['example.com' => 'zone-1']);

        $dns->updateARecord('www.example.com', 'a1', '203.0.113.1');

        $this->assertSame('PATCH', $this->sent(0)['method']);
        $this->assertSame('/client/v4/zones/zone-1/dns_records/a1', $this->sent(0)['path']);
        $this->assertSame(['content' => '203.0.113.1'], $this->sent(0)['body']);
    }

    public function test_a_new_record_uses_the_configured_proxy_setting()
    {
        $dns = $this->provider([$this->ok(['id' => 'a2'])], ['example.com' => 'zone-1'], false);

        $dns->createARecord('www.example.com', '203.0.113.1');

        $this->assertSame('POST', $this->sent(0)['method']);
        $this->assertSame(['type' => 'A', 'name' => 'www.example.com', 'content' => '203.0.113.1', 'proxied' => false, 'ttl' => 1], $this->sent(0)['body']);
    }

    public function test_cloudflare_errors_are_reported()
    {
        $dns = $this->provider([new Response(403, [], json_encode([
            'success' => false,
            'errors' => [['code' => 10000, 'message' => 'Authentication error']],
            'result' => null,
        ]))], ['example.com' => 'zone-1']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('10000 Authentication error');

        $dns->records('example.com');
    }

    public function test_a_token_is_required()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('BLACKSMITH_CLOUDFLARE_TOKEN');

        (new CloudflareDnsProvider(null))->zoneFor('example.com');
    }
}
