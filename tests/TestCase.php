<?php

namespace Tests;

use Grafite\Blacksmith\Actions\SyncWorkers;
use Grafite\Blacksmith\BlacksmithProvider;
use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\Dns\DnsProvider;
use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\State\StateRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Sleep;
use Laravel\Forge\Resources\BackgroundProcess;
use Laravel\Forge\Resources\PHPVersion;
use Laravel\Forge\Resources\ScheduledJob;
use Laravel\Forge\Resources\Server;
use Laravel\Forge\Resources\Site;
use Mockery;
use Mockery\MockInterface;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Tests\Fakes\FakeDnsProvider;

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

        // The fixtures are built around these, so bumping the shipped defaults doesn't break the suite.
        $app['config']->set('blacksmith.defaults.server.ubuntu_version', '24.04');
        $app['config']->set('blacksmith.defaults.server.php_version', 'php84');
        $app['config']->set('blacksmith.defaults.server.php_cli_version', 'php84');
        $app['config']->set('blacksmith.defaults.site.php_version', 'php84');
        $app['config']->set('blacksmith.defaults.worker.php_version', 'php');
        $app['config']->set('blacksmith.defaults.worker.timeout', 60);
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

    /**
     * Turn DNS on with an in-memory provider.
     */
    protected function dns(array $records = [], array $zones = ['example.com' => 'zone-1']): FakeDnsProvider
    {
        config(['blacksmith.dns.provider' => 'fake']);

        $dns = new FakeDnsProvider($records, $zones);
        $this->app->instance(DnsProvider::class, $dns);

        return $dns;
    }

    protected function forge(): MockInterface
    {
        $forge = Mockery::mock(ForgeGateway::class);

        $this->app->instance(ForgeGateway::class, $forge);

        return $forge;
    }

    /**
     * A Forge server that is created, provisioned and reports ready.
     */
    protected function mockNewServer($forge, int $id, string $type = 'app')
    {
        $forge->shouldReceive('createCustomServer')->once()->ordered()->withArgs(fn ($payload) => $payload['provider'] === 'custom' && $payload['type'] === $type)
            ->andReturnUsing(fn ($payload) => new Server(['id' => $id, 'name' => $payload['name'], 'type' => $type, 'provision_command' => "curl forge/{$id} | bash"]));
        $forge->shouldReceive('server')->with($id)->andReturn($this->server(['id' => $id, 'is_ready' => true, 'type' => $type]));
        $forge->shouldReceive('phpVersions')->with($id)->andReturn([new PHPVersion(['version' => '8.4', 'binary_name' => 'php8.4', 'status' => 'installed'])]);
        $forge->shouldReceive('updatePhpLimits')->with($id, Mockery::any(), Mockery::any());
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
            'user' => 'forge',
            'aliases' => ['www.example.com'],
            'web_directory' => '/home/forge/example.com/public',
            'root_directory' => '/home/forge/example.com',
            'app_type' => 'Laravel',
            'php_version' => 'PHP 8.4',
            'repository' => ['provider' => 'GitHub', 'url' => 'git@github.com:acme/example.git', 'branch' => 'main', 'status' => 'installed'],
            'quick_deploy' => false,
            'https' => true,
        ], $attributes));
    }

    /**
     * A queue worker background process in the example.com directory.
     */
    protected function worker(int $id, array $worker = [], array $attributes = []): BackgroundProcess
    {
        return new BackgroundProcess(array_replace([
            'id' => $id,
            'command' => SyncWorkers::toCommand($worker),
            'user' => 'forge',
            'directory' => '/home/forge/example.com',
            'processes' => SyncWorkers::normalize($worker)['processes'],
            'status' => 'running',
        ], $attributes));
    }

    protected function schedulerJob(int $id, string $domain = 'example.com'): ScheduledJob
    {
        return new ScheduledJob(['id' => $id, 'command' => "php8.4 /home/forge/{$domain}/artisan schedule:run"]);
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
