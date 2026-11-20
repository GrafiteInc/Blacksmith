<?php

namespace Tests\Feature;

use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\Dns\DnsProvider;
use Grafite\Blacksmith\ServerProviders\ServerProvider;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Forge\Resources\ServerTypes;
use RuntimeException;
use Tests\Fakes\FakeDnsProvider;
use Tests\Fakes\FakeServerProvider;
use Tests\TestCase;

class ReplaceTest extends TestCase
{
    protected FakeServerProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeServerProvider;
        $this->app->instance(ServerProvider::class, $this->provider);

        Process::fake();
    }

    /**
     * prod-next, built and waiting for cutover.
     */
    protected function seedReplacement(string $instance = 'prod', int $serverId = 800, int $siteId = 801): void
    {
        $this->state()->putInstance("{$instance}-next", [
            'server_id' => $serverId,
            'ip_address' => '203.0.113.1',
            'profile' => 'app',
            'provider' => 'fake',
            'provider_id' => 9001,
            'replaces' => $instance,
        ]);
        $this->state()->setSiteId("{$instance}-next", 'example.com', $siteId);
    }

    public function test_replace_builds_a_copy_without_the_scheduler_workers_or_certificate()
    {
        $this->seedBlueprint();
        $this->blueprints()->saveEnv('example.com', "APP_NAME=Example\nDB_HOST=127.0.0.1\n");
        $this->blueprints()->saveEnvOverride('example.com', 'prod', "APP_DEBUG=false\n");

        $forge = $this->forge();
        $this->mockNewServer($forge, 800);

        $forge->shouldReceive('sites')->with(800)->andReturn([]);
        $forge->shouldReceive('createSite')->once()->andReturn($this->site(['id' => 801, 'https' => false]));
        $forge->shouldReceive('syncAliases', 'updateSiteDeploymentScript')->once();
        $forge->shouldReceive('updateSiteEnvironmentFile')->once()->withArgs(fn ($server, $site, $env) => $server === 800 && Str::contains($env, 'APP_DEBUG=false'));
        $forge->shouldReceive('scheduledJobs')->with(800)->andReturn([]);
        $forge->shouldReceive('backgroundProcesses')->with(800)->andReturn([]);
        $forge->shouldReceive('redirectRules', 'securityRules')->andReturn([]);
        $forge->shouldReceive('deploySite')->once()->with(800, 801);
        $forge->shouldNotReceive('createScheduledJob', 'createBackgroundProcess', 'obtainLetsEncryptCertificate');

        $this->artisan('blacksmith:replace', ['instance' => 'prod'])
            ->expectsOutputToContain('prod-next is ready at 203.0.113.1 with: example.com')
            ->expectsOutputToContain('DB_HOST=127.0.0.1')
            ->expectsOutputToContain('Point DNS for example.com at 203.0.113.1')
            ->expectsOutputToContain('php artisan blacksmith:replace prod --cutover')
            ->assertSuccessful();

        $this->assertSame('prod', $this->state()->instance('prod-next')['replaces']);
        $this->assertSame(800, $this->state()->instance('prod-next')['server_id']);
        $this->assertSame('web', $this->state()->roleFor('prod-next', 'example.com'));
        $this->assertSame('prod', $this->state()->primaryInstanceFor('example.com'));
    }

    public function test_a_load_balancer_replacement_gets_the_pool_nodes()
    {
        $this->seedBlueprint();
        $this->blueprints()->saveProfile('balancer', BlueprintRepository::defaultProfile(ServerTypes::LOADBALANCER));
        $this->state()->putInstance('lb', ['server_id' => 900, 'ip_address' => '10.0.0.9', 'profile' => 'balancer']);
        $this->state()->setSiteId('lb', 'example.com', 901);
        $this->state()->putPool('example.com', ['balancer' => 'lb', 'primary' => 'prod', 'nodes' => ['prod']]);

        $forge = $this->forge();
        $this->mockNewServer($forge, 950, 'loadbalancer');

        $forge->shouldReceive('sites')->with(950)->andReturn([]);
        $forge->shouldReceive('createBalancer')->once()->with(950, ['domain' => 'example.com', 'balancer_method' => 'round_robin'])
            ->andReturn($this->site(['id' => 951, 'https' => false]));
        $forge->shouldReceive('syncAliases')->once();
        $forge->shouldReceive('redirectRules', 'securityRules')->andReturn([]);
        $forge->shouldReceive('updateBalancingNodes')->once()->with(950, 951, [
            ['server_id' => 100, 'weight' => 1, 'down' => false, 'backup' => false, 'port' => 80],
        ], 'round_robin');
        $forge->shouldNotReceive('deploySite', 'obtainLetsEncryptCertificate', 'updateSiteEnvironmentFile');

        $this->artisan('blacksmith:replace', ['instance' => 'lb'])
            ->expectsOutputToContain('Point DNS for example.com at 203.0.113.1')
            ->assertSuccessful();

        $this->assertSame('balancer', $this->state()->roleFor('lb-next', 'example.com'));
    }

    public function test_replace_refuses_twice_and_unknown_instances()
    {
        $this->seedBlueprint();
        $this->seedReplacement();
        $this->forge()->shouldNotReceive('createCustomServer');

        $this->artisan('blacksmith:replace', ['instance' => 'prod'])
            ->expectsOutputToContain('already being replaced by prod-next')
            ->assertFailed();

        $this->artisan('blacksmith:replace', ['instance' => 'missing'])->assertFailed();

        $this->artisan('blacksmith:replace', ['instance' => 'prod', '--cutover' => true, '--abort' => true])
            ->expectsOutputToContain('Pass only one of')
            ->assertFailed();
    }

    public function test_cutover_moves_the_scheduler_workers_and_certificate()
    {
        $this->seedBlueprint();
        $this->seedReplacement();

        $forge = $this->forge();

        $forge->shouldReceive('sites')->with(100)->andReturn([$this->site()]);
        $forge->shouldReceive('scheduledJobs')->with(100)->andReturn([$this->schedulerJob(9)]);
        $forge->shouldReceive('backgroundProcesses')->with(100)->andReturn([$this->worker(1, ['connection' => 'redis', 'processes' => 2])]);
        $forge->shouldReceive('deleteScheduledJob')->once()->with(100, 9);
        $forge->shouldReceive('deleteBackgroundProcess')->once()->with(100, 1);

        $forge->shouldReceive('sites')->with(800)->andReturn([$this->site(['id' => 801, 'https' => false])]);
        $forge->shouldReceive('scheduledJobs')->with(800)->andReturn([]);
        $forge->shouldReceive('backgroundProcesses')->with(800)->andReturn([]);
        $forge->shouldReceive('createScheduledJob')->once()->withArgs(fn ($server) => $server === 800);
        $forge->shouldReceive('createBackgroundProcess')->once()->withArgs(fn ($server, $payload) => $server === 800 && $payload['site_id'] === 801);
        $forge->shouldReceive('obtainLetsEncryptCertificate')->once()->withArgs(fn ($server, $site) => $server === 800 && $site === 801);
        $forge->shouldNotReceive('deleteServer');

        $this->artisan('blacksmith:replace', ['instance' => 'prod', '--cutover' => true])
            ->expectsOutputToContain('prod is now on 203.0.113.1, the old server is kept as prod-old')
            ->expectsConfirmation('Delete the old server prod-old (10.0.0.1) now?', 'no')
            ->expectsOutputToContain('blacksmith:replace prod --finish')
            ->assertSuccessful();

        $this->assertSame(800, $this->state()->instance('prod')['server_id']);
        $this->assertSame(801, $this->state()->siteId('prod', 'example.com'));
        $this->assertArrayNotHasKey('replaces', $this->state()->instance('prod'));
        $this->assertFalse($this->state()->hasInstance('prod-next'));
        $this->assertSame(100, $this->state()->instance('prod-old')['server_id']);
        $this->assertSame('prod-old', $this->state()->retiredFor('prod'));
        $this->assertSame('primary', $this->state()->roleFor('prod', 'example.com'));
        $this->assertSame('web', $this->state()->roleFor('prod-old', 'example.com'));
        $this->assertSame([], $this->provider->destroyed);
    }

    /**
     * The Forge calls of a cutover from prod (100/200) to prod-next (800/801).
     */
    protected function mockCutover($forge, bool $certificate = true)
    {
        $forge->shouldReceive('sites')->with(100)->andReturn([$this->site()]);
        $forge->shouldReceive('scheduledJobs')->with(100)->andReturn([$this->schedulerJob(9)]);
        $forge->shouldReceive('backgroundProcesses')->with(100)->andReturn([$this->worker(1, ['connection' => 'redis', 'processes' => 2])]);
        $forge->shouldReceive('deleteScheduledJob', 'deleteBackgroundProcess')->once();

        $forge->shouldReceive('sites')->with(800)->andReturn([$this->site(['id' => 801, 'https' => false])]);
        $forge->shouldReceive('scheduledJobs')->with(800)->andReturn([]);
        $forge->shouldReceive('backgroundProcesses')->with(800)->andReturn([]);
        $forge->shouldReceive('createScheduledJob', 'createBackgroundProcess')->once();

        if (! $certificate) {
            $forge->shouldNotReceive('obtainLetsEncryptCertificate');
        }
    }

    public function test_cutover_points_dns_at_the_new_server_before_the_certificate()
    {
        $this->seedBlueprint();
        $this->seedReplacement();
        $dns = $this->dns(['example.com' => [['content' => '10.0.0.1']], 'www.example.com' => [['content' => '10.0.0.1']]]);

        $forge = $this->forge();
        $this->mockCutover($forge);
        $forge->shouldReceive('obtainLetsEncryptCertificate')->once()->andReturnUsing(function () use ($dns) {
            $this->assertSame(['203.0.113.1'], $dns->addresses('example.com'), 'DNS moves before the certificate is requested.');
            $this->assertSame(['203.0.113.1'], $dns->addresses('www.example.com'));
        });

        $this->artisan('blacksmith:replace', ['instance' => 'prod', '--cutover' => true])
            ->expectsOutputToContain('DNS example.com: Point A record 10.0.0.1 (prod-old) → 203.0.113.1 (prod)')
            ->expectsConfirmation('Delete the old server prod-old (10.0.0.1) now?', 'no')
            ->assertSuccessful();

        $this->assertCount(2, $dns->calls);
    }

    public function test_cutover_without_dns_leaves_the_records_alone()
    {
        $this->seedBlueprint();
        $this->seedReplacement();
        $dns = $this->dns(['example.com' => [['content' => '10.0.0.1']]]);

        $forge = $this->forge();
        $this->mockCutover($forge);
        $forge->shouldReceive('obtainLetsEncryptCertificate')->once();

        $this->artisan('blacksmith:replace', ['instance' => 'prod', '--cutover' => true, '--no-dns' => true])
            ->expectsConfirmation('Delete the old server prod-old (10.0.0.1) now?', 'no')
            ->assertSuccessful();

        $this->assertSame([], $dns->calls);
    }

    public function test_a_dns_failure_still_moves_the_scheduler_and_workers()
    {
        $this->seedBlueprint();
        $this->seedReplacement();

        config(['blacksmith.dns.provider' => 'fake']);
        $this->app->instance(DnsProvider::class, new class extends FakeDnsProvider
        {
            public function records(string $hostname): array
            {
                throw new RuntimeException('Cloudflare is down');
            }
        });

        $forge = $this->forge();
        $this->mockCutover($forge, certificate: false);

        $this->artisan('blacksmith:replace', ['instance' => 'prod', '--cutover' => true])
            ->expectsOutputToContain('DNS: Cloudflare is down')
            ->expectsOutputToContain('Finish with: php artisan blacksmith:dns prod && php artisan blacksmith:update-server prod --only=ssl')
            ->assertFailed();

        $this->assertSame(800, $this->state()->instance('prod')['server_id']);
        $this->assertSame('prod-old', $this->state()->retiredFor('prod'));
    }

    public function test_replace_says_dns_moves_on_cutover()
    {
        $this->seedBlueprint();
        $this->dns();

        $forge = $this->forge();
        $this->mockNewServer($forge, 800);
        $forge->shouldReceive('sites')->with(800)->andReturn([]);
        $forge->shouldReceive('createSite')->once()->andReturn($this->site(['id' => 801, 'https' => false]));
        $forge->shouldReceive('syncAliases', 'updateSiteDeploymentScript', 'updateSiteEnvironmentFile', 'deploySite')->once();
        $forge->shouldReceive('scheduledJobs', 'backgroundProcesses', 'redirectRules', 'securityRules')->andReturn([]);

        $this->artisan('blacksmith:replace', ['instance' => 'prod'])
            ->doesntExpectOutputToContain('Point DNS for')
            ->expectsOutputToContain('3. php artisan blacksmith:replace prod --cutover')
            ->expectsOutputToContain('DNS for example.com will be pointed at 203.0.113.1 on cutover (fake)')
            ->assertSuccessful();
    }

    public function test_cutover_of_a_pooled_node_repoints_the_balancer()
    {
        $this->seedBlueprint();
        $this->state()->putInstance('lb', ['server_id' => 900, 'profile' => 'balancer']);
        $this->state()->setSiteId('lb', 'example.com', 901);
        $this->state()->putInstance('example-com-node-1', ['server_id' => 1000, 'ip_address' => '10.0.0.2', 'profile' => 'app', 'provider' => 'fake', 'provider_id' => 5000]);
        $this->state()->setSiteId('example-com-node-1', 'example.com', 1001);
        $this->state()->putPool('example.com', ['balancer' => 'lb', 'primary' => 'prod', 'nodes' => ['prod', 'example-com-node-1']]);
        $this->seedReplacement('example-com-node-1', 1100, 1101);

        $forge = $this->forge();
        $forge->shouldReceive('updateBalancingNodes')->once()->ordered()->withArgs(fn ($server, $site, $nodes) => $server === 900
            && collect($nodes)->pluck('server_id')->all() === [100, 1100]);
        $forge->shouldReceive('deleteServer')->once()->ordered()->with(1000);
        $forge->shouldNotReceive('obtainLetsEncryptCertificate', 'createScheduledJob', 'deleteScheduledJob', 'createBackgroundProcess', 'deleteBackgroundProcess');

        $this->artisan('blacksmith:replace', ['instance' => 'example-com-node-1', '--cutover' => true, '--force' => true])
            ->expectsOutputToContain('Deleted example-com-node-1-old')
            ->assertSuccessful();

        $this->assertSame([5000], $this->provider->destroyed);
        $this->assertFalse($this->state()->hasInstance('example-com-node-1-old'));
        $this->assertSame(1100, $this->state()->instance('example-com-node-1')['server_id']);
        $this->assertSame(['prod', 'example-com-node-1'], $this->state()->pool('example.com')['nodes']);
    }

    public function test_finish_deletes_the_old_server()
    {
        $this->seedBlueprint();
        $this->state()->putInstance('prod-old', ['server_id' => 300, 'ip_address' => '10.0.0.3', 'profile' => 'app', 'provider' => 'fake', 'provider_id' => 5000, 'retired_from' => 'prod']);

        $forge = $this->forge();
        $forge->shouldReceive('deleteServer')->once()->with(300);

        $this->artisan('blacksmith:replace', ['instance' => 'prod', '--finish' => true])
            ->expectsConfirmation('Delete the server prod-old (10.0.0.3)?', 'yes')
            ->expectsOutputToContain('Deleted prod-old')
            ->assertSuccessful();

        $this->assertSame([5000], $this->provider->destroyed);
        $this->assertFalse($this->state()->hasInstance('prod-old'));
        $this->assertTrue($this->state()->hasInstance('prod'));
    }

    public function test_abort_cleans_up_a_half_built_replacement()
    {
        $this->seedBlueprint();
        $this->state()->putInstance('prod-next', ['server_id' => null, 'profile' => 'app', 'provider' => 'fake', 'provider_id' => 9001, 'replaces' => 'prod']);

        $this->forge()->shouldNotReceive('deleteServer');

        $this->artisan('blacksmith:replace', ['instance' => 'prod', '--abort' => true, '--force' => true])
            ->expectsOutputToContain('Deleted prod-next')
            ->assertSuccessful();

        $this->assertSame([9001], $this->provider->destroyed);
        $this->assertFalse($this->state()->hasInstance('prod-next'));
    }

    public function test_cutover_needs_a_finished_replacement()
    {
        $this->seedBlueprint();
        $this->forge();

        $this->artisan('blacksmith:replace', ['instance' => 'prod', '--cutover' => true])
            ->expectsOutputToContain('prod is not being replaced')
            ->assertFailed();

        $this->state()->putInstance('prod-next', ['server_id' => null, 'replaces' => 'prod']);

        $this->artisan('blacksmith:replace', ['instance' => 'prod', '--cutover' => true])
            ->expectsOutputToContain('did not finish building')
            ->assertFailed();
    }
}
