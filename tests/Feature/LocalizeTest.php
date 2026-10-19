<?php

namespace Tests\Feature;

use Laravel\Forge\Resources\Job;
use Laravel\Forge\Resources\RedirectRule;
use Laravel\Forge\Resources\SecurityRule;
use Laravel\Forge\Resources\Worker;
use Tests\TestCase;

class LocalizeTest extends TestCase
{
    protected function mockServer($forge, array $server, array $site, string $env)
    {
        $forge->shouldReceive('server')->with($server['id'])->andReturn($this->server($server));
        $forge->shouldReceive('sites')->with($server['id'])->andReturn([$this->site($site), $this->site(['id' => 1, 'name' => 'default'])]);
        $forge->shouldReceive('jobs')->with($server['id'])->andReturn([
            new Job(['id' => 1, 'command' => 'php8.4 /home/forge/example.com/artisan schedule:run']),
        ]);
        $forge->shouldReceive('workers')->with($server['id'], $site['id'])->andReturn([
            new Worker(['id' => 1, 'connection' => 'redis', 'queue' => 'emails', 'processes' => 3, 'daemon' => 1, 'php_version' => 'php84']),
        ]);
        $forge->shouldReceive('redirectRules')->andReturn([new RedirectRule(['id' => 1, 'from' => '/old', 'to' => '/new', 'type' => 'permanent'])]);
        $forge->shouldReceive('securityRules')->andReturn([
            new SecurityRule(['id' => 1, 'name' => 'Admin', 'path' => '/admin', 'credentials' => [['id' => 1, 'username' => 'matt']]]),
        ]);
        $forge->shouldReceive('siteEnvironmentFile')->with($server['id'], $site['id'])->andReturn($env);
        $forge->shouldReceive('siteDeploymentScript')->andReturn("git pull\n");
    }

    public function test_localize_pulls_a_server_into_blueprints_and_state()
    {
        $this->root = base_path('.blacksmith-'.uniqid());
        config(['blacksmith.path' => $this->root]);

        $gitignore = file_exists(base_path('.gitignore')) ? file_get_contents(base_path('.gitignore')) : null;
        file_put_contents(base_path('.gitignore'), "/vendor\n");

        $forge = $this->forge();
        $this->mockServer($forge, ['id' => 100], ['id' => 200], "APP_URL=https://example.com\n");

        $this->artisan('blacksmith:localize', ['--server' => 100])->assertSuccessful();

        $site = $this->blueprints()->site('example.com');

        $this->assertSame(['www.example.com'], $site['aliases']);
        $this->assertSame(['example.com', 'www.example.com'], $site['ssl_domains']);
        $this->assertSame('acme/example', $site['repository']['repository']);
        $this->assertTrue($site['scheduler']);
        $this->assertSame('emails', $site['workers'][0]['queue']);
        $this->assertTrue($site['workers'][0]['daemon']);
        $this->assertSame([['from' => '/old', 'to' => '/new', 'type' => 'permanent']], $site['redirects']);
        $this->assertSame([['username' => 'matt', 'password' => '']], $site['security'][0]['credentials']);
        $this->assertSame("APP_URL=https://example.com\n", $this->blueprints()->baseEnv('example.com'));
        $this->assertSame("git pull\n", $this->blueprints()->deployScript('example.com'));
        $this->assertFalse($this->blueprints()->hasSite('default'));

        $this->assertSame('app', $this->state()->instance('production')['profile']);
        $this->assertSame(200, $this->state()->siteId('production', 'example.com'));
        $this->assertTrue($this->blueprints()->profile('app')['opcache']);

        $this->assertSame("/vendor\n/".basename($this->root).PHP_EOL, file_get_contents(base_path('.gitignore')));

        is_null($gitignore) ? unlink(base_path('.gitignore')) : file_put_contents(base_path('.gitignore'), $gitignore);
    }

    public function test_a_second_instance_of_a_site_saves_env_overrides()
    {
        $forge = $this->forge();
        $this->mockServer($forge, ['id' => 100], ['id' => 200], "APP_NAME=Example\nAPP_URL=https://example.com\n");
        $this->mockServer($forge, ['id' => 101, 'name' => 'Staging'], ['id' => 201], "APP_NAME=Example\nAPP_URL=https://staging.example.com\n");

        $this->artisan('blacksmith:localize', ['--server' => 100])->assertSuccessful();
        $this->artisan('blacksmith:localize', ['--server' => 101])->assertSuccessful();

        $this->assertSame("APP_NAME=Example\nAPP_URL=https://example.com\n", $this->blueprints()->baseEnv('example.com'));
        $this->assertSame('APP_URL=https://staging.example.com'.PHP_EOL, file_get_contents($this->blueprints()->envOverridePath('example.com', 'staging')));
        $this->assertStringContainsString('APP_URL=https://staging.example.com', $this->blueprints()->env('example.com', 'staging'));
    }

    public function test_localize_without_a_server_requires_interaction()
    {
        $this->forge();

        $this->artisan('blacksmith:localize', ['--no-interaction' => true])
            ->expectsOutputToContain('Pass --server=ID')
            ->assertFailed();
    }

    public function test_balancer_nodes_are_recorded_as_a_pool()
    {
        $forge = $this->forge();
        $this->mockServer($forge, ['id' => 100], ['id' => 200], "APP_URL=https://example.com\n");

        $forge->shouldReceive('server')->with(300)->andReturn($this->server(['id' => 300, 'name' => 'Balancer', 'type' => 'loadbalancer']));
        $forge->shouldReceive('sites')->with(300)->andReturn([$this->site(['id' => 301])]);
        $forge->shouldReceive('get')->with('servers/300/sites/301/balancing')->andReturn([
            'nodes' => [['server_id' => 100, 'weight' => 2, 'down' => false, 'backup' => false, 'port' => 80]],
        ]);

        $this->artisan('blacksmith:localize', ['--server' => 300])->assertSuccessful();

        $pool = $this->state()->pool('example.com');

        $this->assertSame('balancer', $pool['balancer']);
        $this->assertSame('production', $pool['primary']);
        $this->assertSame(['production'], $pool['nodes']);
        $this->assertSame(2, $pool['options']['production']['weight']);
        $this->assertSame('balancer', $this->state()->instance('balancer')['profile']);
        $this->assertTrue($this->blueprints()->hasSite('example.com'));
    }
}
