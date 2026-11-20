<?php

namespace Tests\Unit;

use Grafite\Blacksmith\Forge\ForgeGateway;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Laravel\Forge\Forge;
use Laravel\Forge\Resources\Site;
use RuntimeException;
use Tests\TestCase;

/**
 * Runs the gateway through the real SDK against faked HTTP responses,
 * so every request is checked against the Forge API v2 routes.
 */
class ForgeGatewayTest extends TestCase
{
    protected array $requests = [];

    protected function gateway(array $responses, ?string $organization = 'acme'): ForgeGateway
    {
        $this->requests = [];

        $stack = HandlerStack::create(new MockHandler(array_map(
            fn ($body) => $body instanceof Response ? $body : new Response(is_null($body) ? 202 : 200, [], is_null($body) ? '' : json_encode($body)),
            $responses,
        )));
        $stack->push(Middleware::history($this->requests));

        $client = new Client(['base_uri' => 'https://forge.laravel.com/api/', 'handler' => $stack, 'http_errors' => false]);

        return new ForgeGateway(new Forge('token', $client), $organization);
    }

    protected function sent(): array
    {
        return array_map(fn ($transaction) => [
            $transaction['request']->getMethod(),
            $transaction['request']->getUri()->getPath(),
            json_decode((string) $transaction['request']->getBody(), true),
        ], $this->requests);
    }

    protected static function item(int $id, array $attributes): array
    {
        return ['id' => $id, 'type' => 'items', 'attributes' => $attributes];
    }

    public function test_the_configured_organization_is_used()
    {
        $gateway = $this->gateway([['data' => self::item(100, ['name' => 'prod'])]]);

        $this->assertSame('prod', $gateway->server(100)->name);
        $this->assertSame([['GET', '/api/orgs/acme/servers/100', null]], $this->sent());
    }

    public function test_a_single_organization_is_found_from_the_token()
    {
        $gateway = $this->gateway([
            ['data' => [self::item(1, ['slug' => 'solo', 'name' => 'Solo'])]],
            ['data' => []],
        ], null);

        $this->assertSame([], $gateway->servers());
        $this->assertSame('solo', $gateway->organization());
        $this->assertSame('/api/orgs/solo/servers', $this->sent()[1][1]);
    }

    public function test_several_organizations_must_be_chosen_between()
    {
        $gateway = $this->gateway([
            ['data' => [self::item(1, ['slug' => 'one']), self::item(2, ['slug' => 'two'])]],
        ], null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('several organizations (one, two), set BLACKSMITH_FORGE_ORGANIZATION');

        $gateway->organization();
    }

    public function test_lists_follow_every_page()
    {
        $gateway = $this->gateway([
            ['data' => [self::item(1, ['name' => 'a.com'])], 'meta' => ['next_cursor' => 'abc']],
            ['data' => [self::item(2, ['name' => 'b.com'])], 'meta' => ['next_cursor' => null]],
        ]);

        $this->assertSame(['a.com', 'b.com'], array_map(fn ($site) => $site->name, $gateway->sites(100)));
        $this->assertSame('page%5Bcursor%5D=abc', $this->requests[1]['request']->getUri()->getQuery());
    }

    public function test_custom_servers_nest_their_addresses_and_do_not_wait()
    {
        $gateway = $this->gateway([['data' => self::item(700, ['name' => 'box', 'provision_command' => 'curl x | bash'])]]);

        $server = $gateway->createCustomServer(['provider' => 'custom', 'name' => 'box', 'ip_address' => '1.2.3.4', 'private_ip_address' => null]);

        $this->assertSame('curl x | bash', $server->provisionCommand);
        $this->assertSame([['POST', '/api/orgs/acme/servers', [
            'provider' => 'custom',
            'name' => 'box',
            'custom' => ['ip_address' => '1.2.3.4'],
        ]]], $this->sent());
    }

    public function test_forge_rejections_name_the_fields_forge_gave()
    {
        $gateway = $this->gateway([
            new Response(422, [], json_encode(['message' => 'The given data was invalid.', 'errors' => [
                'php_version' => ['The selected php version is invalid.'],
                'database_type' => ['The selected database type is invalid.'],
            ]])),
            new Response(422, [], json_encode(['message' => 'The ubuntu version is not supported.'])),
            new Response(400, [], json_encode(['message' => 'The server is still provisioning.'])),
        ]);

        $rejected = function (callable $call) {
            try {
                $call();
            } catch (RuntimeException $exception) {
                return $exception->getMessage();
            }

            $this->fail('Forge\'s rejection was not turned into a RuntimeException.');
        };

        $this->assertSame(
            'Forge rejected creating server box: php_version: The selected php version is invalid.; database_type: The selected database type is invalid.',
            $rejected(fn () => $gateway->createCustomServer(['provider' => 'custom', 'name' => 'box', 'ip_address' => '1.2.3.4'])),
        );
        $this->assertSame(
            'Forge rejected creating server box: The ubuntu version is not supported.',
            $rejected(fn () => $gateway->createCustomServer(['provider' => 'custom', 'name' => 'box', 'ip_address' => '1.2.3.4'])),
        );
        $this->assertSame(
            'Forge rejected installing php85 on server 700: The server is still provisioning.',
            $rejected(fn () => $gateway->installPHP(700, 'php85')),
        );
    }

    public function test_aliases_are_synced_as_domain_records()
    {
        $gateway = $this->gateway([
            ['data' => [
                self::item(1, ['name' => 'example.com']),
                self::item(2, ['name' => 'old.example.com']),
                self::item(3, ['name' => 'www.example.com']),
            ]],
            null,
            ['data' => self::item(4, ['name' => 'app.example.com'])],
        ]);

        $gateway->syncAliases(100, 200, 'example.com', ['www.example.com', 'app.example.com']);

        $this->assertSame([
            ['GET', '/api/orgs/acme/servers/100/sites/200/domains', null],
            ['DELETE', '/api/orgs/acme/servers/100/sites/200/domains/2', null],
            ['POST', '/api/orgs/acme/servers/100/sites/200/domains', ['name' => 'app.example.com', 'allow_wildcard_subdomains' => false, 'www_redirect_type' => 'none']],
        ], $this->sent());
    }

    public function test_lets_encrypt_is_requested_per_domain_record()
    {
        $gateway = $this->gateway([
            ['data' => [self::item(1, ['name' => 'example.com']), self::item(3, ['name' => 'www.example.com']), self::item(4, ['name' => 'skip.com'])]],
            ['data' => self::item(10, ['type' => 'letsencrypt'])],
            ['data' => self::item(11, ['type' => 'letsencrypt'])],
        ]);

        $gateway->obtainLetsEncryptCertificate(100, 200, ['example.com', 'www.example.com']);

        $body = ['type' => 'letsencrypt', 'letsencrypt' => ['verification_method' => 'http-01']];

        $this->assertSame([
            ['GET', '/api/orgs/acme/servers/100/sites/200/domains', null],
            ['POST', '/api/orgs/acme/servers/100/sites/200/domains/1/certificates', $body],
            ['POST', '/api/orgs/acme/servers/100/sites/200/domains/3/certificates', $body],
        ], $this->sent());
    }

    public function test_server_and_site_settings_use_the_v2_routes()
    {
        $gateway = $this->gateway([...array_fill(0, 8, null), ['data' => self::item(5, ['status' => 'pending'])]]);

        $gateway->updatePhpLimits(100, 64, 120);
        $gateway->enableOPCache(100);
        $gateway->installPHP(100, 'php85');
        $gateway->updateSiteDeploymentScript(100, 200, 'git pull');
        $gateway->enableQuickDeploy(100, 200);
        $gateway->updateBalancingNodes(100, 200, [['server_id' => 1, 'weight' => 1]], 'round_robin');
        $gateway->restartBackgroundProcess(100, 9);
        $gateway->deploySite(100, 200);

        $this->assertSame([
            ['PUT', '/api/orgs/acme/servers/100/php/max-upload-size', ['max_upload_size' => 64]],
            ['PUT', '/api/orgs/acme/servers/100/php/max-execution-time', ['max_execution_time' => 120]],
            ['POST', '/api/orgs/acme/servers/100/php/opcache', null],
            ['POST', '/api/orgs/acme/servers/100/php/versions', ['version' => 'php85']],
            ['PUT', '/api/orgs/acme/servers/100/sites/200/deployments/script', ['content' => 'git pull']],
            ['POST', '/api/orgs/acme/servers/100/sites/200/deployments/push-to-deploy', null],
            ['PUT', '/api/orgs/acme/servers/100/sites/200/load-balancing-nodes', ['balancer_method' => 'round_robin', 'balancing' => [['server_id' => 1, 'weight' => 1]]]],
            ['POST', '/api/orgs/acme/servers/100/background-processes/9/actions', ['action' => 'restart']],
        ], array_slice($this->sent(), 0, 8));
        $this->assertSame(['POST', '/api/orgs/acme/servers/100/sites/200/deployments', null], $this->sent()[8]);
    }

    public function test_deploy_and_wait_polls_until_the_deployment_finishes()
    {
        $gateway = $this->gateway([
            ['data' => self::item(5, ['status' => 'queued'])],
            ['data' => self::item(5, ['status' => 'deploying'])],
            ['data' => self::item(5, ['status' => 'finished'])],
        ]);

        $this->assertSame('finished', $gateway->deployAndWait(100, 200)->status);
        $this->assertSame([
            ['POST', '/api/orgs/acme/servers/100/sites/200/deployments', null],
            ['GET', '/api/orgs/acme/servers/100/sites/200/deployments/5', null],
            ['GET', '/api/orgs/acme/servers/100/sites/200/deployments/5', null],
        ], $this->sent());
    }

    public function test_a_failed_deployment_throws_with_the_end_of_its_log()
    {
        $gateway = $this->gateway([
            ['data' => self::item(5, ['status' => 'queued'])],
            ['data' => self::item(5, ['status' => 'failed-build'])],
            ['data' => ['attributes' => ['output' => "Installing\nnpm ERR! build failed"]]],
        ]);

        try {
            $gateway->deployAndWait(100, 200);
            $this->fail('A failed deployment should throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Deployment 5 ended as failed-build.', $exception->getMessage());
            $this->assertStringContainsString('npm ERR! build failed', $exception->getMessage());
        }

        $this->assertSame(['GET', '/api/orgs/acme/servers/100/sites/200/deployments/5/log', null], $this->sent()[2]);
    }

    public function test_a_deployment_that_never_finishes_times_out()
    {
        config(['blacksmith.deploy_timeout' => 2]);

        $gateway = $this->gateway([
            ['data' => self::item(5, ['status' => 'queued'])],
            ['data' => self::item(5, ['status' => 'deploying'])],
            ['data' => self::item(5, ['status' => 'deploying'])],
            ['data' => ['attributes' => ['output' => '']]],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Deployment 5 was still deploying after 2 seconds.');

        $gateway->deployAndWait(100, 200);
    }

    public function test_site_root_and_repository_helpers()
    {
        $site = new Site(['name' => 'example.com', 'user' => 'forge', 'repository' => ['provider' => 'github', 'url' => 'git@github.com:acme/example.git', 'branch' => 'main']]);

        $this->assertSame('/home/forge/example.com', ForgeGateway::siteRoot($site));
        $this->assertSame(['provider' => 'github', 'repository' => 'acme/example', 'branch' => 'main'], ForgeGateway::siteRepository($site));

        $zeroDowntime = new Site(['name' => 'example.com', 'root_directory' => '/home/deploy/example.com/', 'zero_downtime_deployments' => true, 'repository' => ['url' => 'https://gitlab.com/acme/example']]);

        $this->assertSame('/home/deploy/example.com/current', ForgeGateway::siteRoot($zeroDowntime));
        $this->assertSame('acme/example', ForgeGateway::siteRepository($zeroDowntime)['repository']);
        $this->assertSame('acme/example', ForgeGateway::siteRepository(new Site(['repository' => 'acme/example']))['repository']);
        $this->assertNull(ForgeGateway::siteRepository(new Site(['repository' => null])));
    }
}
