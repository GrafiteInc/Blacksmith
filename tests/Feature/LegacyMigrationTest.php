<?php

namespace Tests\Feature;

use Grafite\Blacksmith\Blueprint\LegacyMigrator;
use Tests\TestCase;

class LegacyMigrationTest extends TestCase
{
    protected function writeLegacyServer(int $id, array $config, array $files = [])
    {
        mkdir($this->root.'/'.$id, 0755, true);
        file_put_contents($this->root.'/'.$id.'/config.json', json_encode($config));

        foreach ($files as $name => $contents) {
            file_put_contents($this->root.'/'.$id.'/'.$name, $contents);
        }
    }

    public function test_the_v1_layout_is_converted()
    {
        $this->writeLegacyServer(100, [
            'server' => ['id' => 100, 'name' => 'Production', 'type' => 'app', 'php_version' => 'php83', 'max_upload_size' => 64, 'opcache_enabled' => false],
            'sites' => [[
                'id' => 200,
                'domain' => 'example.com',
                'php_version' => 'php83',
                'directory' => '/public',
                'lets_encrypt' => true,
                'scheduler_enabled' => true,
                'ssl_domains' => ['example.com', 'www.example.com'],
                'enable_quick_deploy' => true,
                'repository' => ['provider' => 'github', 'repository' => 'acme/example', 'branch' => 'main', 'composer' => true],
                'environment_variables_file' => 'example.com.env',
                'deployment_file' => 'example.com.deploy',
                'workers' => [['connection' => 'redis', 'queue' => 'default', 'processes' => 2, 'daemon' => true]],
                'security' => [['type' => 'basic', 'value' => 'x'], ['name' => 'Admin', 'path' => '/admin', 'credentials' => ['username' => 'matt', 'password' => 'secret']]],
                'redirects' => [['from' => '', 'to' => '', 'type' => 'permanent'], ['from' => '/a', 'to' => '/b', 'code' => 301]],
            ]],
        ], ['example.com.env' => "APP_URL=https://example.com\n", 'example.com.deploy' => "git pull\n"]);

        $this->writeLegacyServer(300, [
            'server' => ['id' => 300, 'name' => 'Balancer', 'type' => 'loadbalancer'],
            'sites' => [[
                'id' => 301,
                'domain' => 'example.com',
                'balancing' => [['server_id' => 100, 'weight' => 1]],
                'balancing_type' => 'round-robin',
            ]],
        ]);

        $instances = $this->app->make(LegacyMigrator::class)->migrate();

        $this->assertSame(['production', 'balancer'], $instances);
        $this->assertSame([], $this->app->make(LegacyMigrator::class)->detect());
        $this->assertFileExists($this->root.'/legacy/100/config.json');

        $site = $this->blueprints()->site('example.com');
        $this->assertSame('php83', $site['php_version']);
        $this->assertTrue($site['quick_deploy']);
        $this->assertTrue($site['scheduler']);
        $this->assertSame([['from' => '/a', 'to' => '/b', 'type' => 'permanent']], $site['redirects']);
        $this->assertSame([['name' => 'Admin', 'path' => '/admin', 'credentials' => [['username' => 'matt', 'password' => 'secret']]]], $site['security']);
        $this->assertSame('redis', $site['workers'][0]['connection']);
        $this->assertSame("APP_URL=https://example.com\n", $this->blueprints()->baseEnv('example.com'));
        $this->assertSame("git pull\n", $this->blueprints()->deployScript('example.com'));

        $this->assertSame(64, $this->blueprints()->profile('app')['max_upload_size']);
        $this->assertFalse($this->blueprints()->profile('app')['opcache']);
        $this->assertSame('loadbalancer', $this->blueprints()->profile('balancer')['type']);

        $this->assertSame(200, $this->state()->siteId('production', 'example.com'));
        $this->assertSame(['balancer' => 'balancer', 'primary' => 'production', 'nodes' => ['production'], 'method' => 'round_robin'], $this->state()->pool('example.com'));
    }

    public function test_localize_runs_the_migration_first()
    {
        $this->writeLegacyServer(100, [
            'server' => ['id' => 100, 'name' => 'Production', 'type' => 'app'],
            'sites' => [],
        ]);

        $forge = $this->forge();
        $forge->shouldReceive('server')->with(100)->andReturn($this->server());
        $forge->shouldReceive('sites')->with(100)->andReturn([]);
        $forge->shouldReceive('jobs')->with(100)->andReturn([]);

        $this->artisan('blacksmith:localize')
            ->expectsOutput('Migrating the v1 configuration.')
            ->assertSuccessful();

        $this->assertSame(100, $this->state()->instance('production')['server_id']);
    }
}
