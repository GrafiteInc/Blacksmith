<?php

namespace Tests;

use Grafite\Blacksmith\BlacksmithProvider;
use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\State\StateRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Sleep;
use Laravel\Forge\Forge;
use Laravel\Forge\Resources\Server;
use Laravel\Forge\Resources\Site;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected string $root;

    protected function getPackageProviders($app)
    {
        return [
            BlacksmithProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $this->root = sys_get_temp_dir().'/blacksmith-tests-'.uniqid();

        $app['config']->set('blacksmith.path', $this->root);
        $app['config']->set('blacksmith.forge_token', 'test-token');
        $app['config']->set('blacksmith.poll_interval', 0);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->root);

        parent::tearDown();
    }

    protected function forge(): MockInterface
    {
        $forge = Mockery::mock(Forge::class);

        $this->app->instance(Forge::class, $forge);

        return $forge;
    }

    protected function blueprints(): BlueprintRepository
    {
        return $this->app->make(BlueprintRepository::class);
    }

    protected function state(): StateRepository
    {
        return $this->app->make(StateRepository::class);
    }

    protected function server(array $attributes = []): Server
    {
        return new Server(array_replace([
            'id' => 100,
            'name' => 'Production',
            'type' => 'app',
            'ip_address' => '10.0.0.1',
            'private_ip_address' => '10.1.0.1',
            'php_version' => 'php84',
            'php_cli_version' => 'php84',
            'ubuntu_version' => '24.04',
            'opcache_status' => 'enabled',
            'is_ready' => true,
        ], $attributes));
    }

    protected function site(array $attributes = []): Site
    {
        return new Site(array_replace([
            'id' => 200,
            'name' => 'example.com',
            'aliases' => ['www.example.com'],
            'directory' => '/public',
            'project_type' => 'php',
            'php_version' => 'php84',
            'repository' => 'acme/example',
            'repository_provider' => 'github',
            'repository_branch' => 'main',
            'quick_deploy' => false,
            'is_secured' => true,
        ], $attributes));
    }

    /**
     * A blueprint for example.com on a "prod" instance that matches $this->site().
     */
    protected function seedBlueprint(array $site = []): void
    {
        $this->blueprints()->saveProfile('app', BlueprintRepository::defaultProfile());

        $this->blueprints()->saveSite('example.com', array_replace(BlueprintRepository::defaultSite('example.com'), [
            'aliases' => ['www.example.com'],
            'ssl_domains' => ['example.com', 'www.example.com'],
            'repository' => ['provider' => 'github', 'repository' => 'acme/example', 'branch' => 'main', 'composer' => true],
            'scheduler' => true,
            'workers' => [['connection' => 'redis', 'queue' => 'default', 'processes' => 2]],
        ], $site));

        $this->blueprints()->saveEnv('example.com', "APP_NAME=Example\nAPP_URL=https://example.com\n");
        $this->blueprints()->saveDeployScript('example.com', "cd /home/forge/example.com\ngit pull\n");

        $this->state()->putInstance('prod', [
            'server_id' => 100,
            'ip_address' => '10.0.0.1',
            'private_ip_address' => '10.1.0.1',
            'profile' => 'app',
        ]);
        $this->state()->setSiteId('prod', 'example.com', 200);
    }
}
