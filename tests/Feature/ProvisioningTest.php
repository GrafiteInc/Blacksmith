<?php

namespace Tests\Feature;

use Grafite\Blacksmith\ServerProviders\ServerProvider;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\Fakes\FakeServerProvider;
use Tests\TestCase;

class ProvisioningTest extends TestCase
{
    protected FakeServerProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeServerProvider;
        $this->app->instance(ServerProvider::class, $this->provider);

        Process::fake();
    }

    public function test_make_server_creates_registers_and_provisions()
    {
        $forge = $this->forge();
        $this->mockNewServer($forge, 700);

        $this->artisan('blacksmith:make-server', ['instance' => 'worker-1'])
            ->expectsOutputToContain('worker-1: Running the Forge provision script')
            ->assertSuccessful();

        $instance = $this->state()->instance('worker-1');

        $this->assertSame(['worker-1'], $this->provider->created);
        $this->assertSame(700, $instance['server_id']);
        $this->assertSame('203.0.113.1', $instance['ip_address']);
        $this->assertSame('10.10.0.1', $instance['private_ip_address']);
        $this->assertSame(9001, $instance['provider_id']);
        $this->assertTrue($this->blueprints()->hasProfile('app'));

        Process::assertRan(fn (PendingProcess $process) => end($process->command) === 'curl forge/700 | bash'
            && in_array('root@203.0.113.1', $process->command, true));
    }

    public function test_make_server_with_an_existing_ip_skips_the_provider()
    {
        $forge = $this->forge();
        $this->mockNewServer($forge, 701);

        $this->artisan('blacksmith:make-server', ['instance' => 'box', '--ip' => '198.51.100.5'])->assertSuccessful();

        $this->assertSame([], $this->provider->created);
        $this->assertSame('198.51.100.5', $this->state()->instance('box')['ip_address']);
    }

    public function test_a_failed_provision_script_is_reported()
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'apt is locked', exitCode: 1)]);

        $forge = $this->forge();
        $forge->shouldNotReceive('createCustomServer');

        $this->artisan('blacksmith:make-server', ['instance' => 'broken', '--ip' => '198.51.100.6'])
            ->expectsOutputToContain('Unable to connect')
            ->assertFailed();
    }

    public function test_instance_builds_every_site_and_deploys()
    {
        $this->seedBlueprint();

        $forge = $this->forge();
        $this->mockNewServer($forge, 800);

        $forge->shouldReceive('sites')->with(800)->andReturn([]);
        $forge->shouldReceive('createSite')->once()->withArgs(fn ($server, $payload) => $payload['repository'] === 'acme/example')
            ->andReturn($this->site(['id' => 801, 'https' => false]));
        $forge->shouldReceive('syncAliases')->once();
        $forge->shouldReceive('updateSiteEnvironmentFile')->once();
        $forge->shouldReceive('updateSiteDeploymentScript')->once();
        $forge->shouldReceive('scheduledJobs')->with(800)->andReturn([]);
        $forge->shouldReceive('createScheduledJob')->once()->withArgs(fn ($server, $job) => $job['command'] === 'php8.4 /home/forge/example.com/artisan schedule:run');
        $forge->shouldReceive('backgroundProcesses')->with(800)->andReturn([]);
        $forge->shouldReceive('createBackgroundProcess')->once();
        $forge->shouldReceive('obtainLetsEncryptCertificate')->once();
        $forge->shouldReceive('redirectRules', 'securityRules')->andReturn([]);
        $forge->shouldReceive('deploySite')->once()->with(800, 801);

        $this->artisan('blacksmith:instance', ['name' => 'staging'])
            ->expectsOutputToContain('Instance staging is live at 203.0.113.1 with: example.com')
            ->assertSuccessful();

        $this->assertSame(801, $this->state()->siteId('staging', 'example.com'));
        $this->assertSame('prod', $this->state()->primaryInstanceFor('example.com'));
    }

    public function test_balancer_puts_a_pool_in_front_of_the_primary()
    {
        $this->seedBlueprint();

        $forge = $this->forge();
        $this->mockNewServer($forge, 900, 'loadbalancer');

        $forge->shouldReceive('sites')->with(900)->andReturn([]);
        $forge->shouldReceive('createBalancer')->once()->with(900, ['domain' => 'example.com', 'balancer_method' => 'least_conn'])
            ->andReturn($this->site(['id' => 901, 'https' => false]));
        $forge->shouldReceive('syncAliases')->once()->with(900, 901, 'example.com', ['www.example.com']);
        $forge->shouldReceive('redirectRules', 'securityRules')->andReturn([]);
        $forge->shouldReceive('updateBalancingNodes')->once()->with(900, 901, [
            ['server_id' => 100, 'weight' => 1, 'down' => false, 'backup' => false, 'port' => 80],
        ], 'least_conn');
        $forge->shouldNotReceive('createSite', 'obtainLetsEncryptCertificate', 'createBackgroundProcess', 'updateSiteEnvironmentFile');

        $this->artisan('blacksmith:balancer', ['name' => 'lb', '--method' => 'least_conn'])
            ->expectsOutputToContain('Point DNS at the balancer')
            ->assertSuccessful();

        $pool = $this->state()->pool('example.com');

        $this->assertSame('lb', $pool['balancer']);
        $this->assertSame('prod', $pool['primary']);
        $this->assertSame(['prod'], $pool['nodes']);
        $this->assertSame('loadbalancer', $this->blueprints()->profile('balancer')['type']);
    }

    public function test_instance_with_dns_points_it_before_the_certificate()
    {
        $this->seedBlueprint();
        $dns = $this->dns(['example.com' => [['content' => '10.0.0.1']], 'www.example.com' => [['type' => 'CNAME', 'content' => 'example.com']]]);

        $forge = $this->forge();
        $this->mockNewServer($forge, 800);

        $forge->shouldReceive('sites')->with(800)->andReturn([], [$this->site(['id' => 801, 'https' => false])]);
        $forge->shouldReceive('createSite')->once()->andReturn($this->site(['id' => 801, 'https' => false]));
        $forge->shouldReceive('syncAliases', 'updateSiteEnvironmentFile', 'updateSiteDeploymentScript', 'createScheduledJob', 'createBackgroundProcess')->once();
        $forge->shouldReceive('scheduledJobs', 'backgroundProcesses', 'redirectRules', 'securityRules')->andReturn([]);
        $forge->shouldReceive('obtainLetsEncryptCertificate')->once()->andReturnUsing(function () use ($dns) {
            $this->assertSame(['203.0.113.1'], $dns->addresses('example.com'), 'DNS moves before the certificate is requested.');
        });
        $forge->shouldReceive('deploySite')->once()->with(800, 801);

        $this->artisan('blacksmith:instance', ['name' => 'staging', '--dns' => true])
            ->expectsOutputToContain('DNS example.com: Point A record 10.0.0.1 (prod) → 203.0.113.1 (staging)')
            ->doesntExpectOutputToContain('Point DNS at it when you are ready')
            ->assertSuccessful();

        $this->assertSame(['update example.com example.com-0 203.0.113.1'], $dns->calls);
    }

    public function test_instance_dns_needs_a_provider()
    {
        $this->seedBlueprint();
        $this->forge()->shouldNotReceive('createCustomServer');

        $this->artisan('blacksmith:instance', ['name' => 'staging', '--dns' => true])
            ->expectsOutputToContain('--dns needs a DNS provider')
            ->assertFailed();
    }

    public function test_balancer_takes_over_dns_and_gets_its_certificate()
    {
        $this->seedBlueprint();
        $dns = $this->dns(['example.com' => [['content' => '10.0.0.1']], 'www.example.com' => [['content' => '10.0.0.1']]]);

        $forge = $this->forge();
        $this->mockNewServer($forge, 900, 'loadbalancer');

        $forge->shouldReceive('sites')->with(900)->andReturn([], [$this->site(['id' => 901, 'https' => false])]);
        $forge->shouldReceive('createBalancer')->once()->andReturn($this->site(['id' => 901, 'https' => false]));
        $forge->shouldReceive('syncAliases', 'updateBalancingNodes')->once();
        $forge->shouldReceive('redirectRules', 'securityRules')->andReturn([]);
        $forge->shouldReceive('obtainLetsEncryptCertificate')->once()->withArgs(fn ($server, $site) => $server === 900 && $site === 901)
            ->andReturnUsing(function () use ($dns) {
                $this->assertSame(['203.0.113.1'], $dns->addresses('www.example.com'), 'DNS moves before the certificate is requested.');
            });

        $this->artisan('blacksmith:balancer', ['name' => 'lb'])
            ->doesntExpectOutputToContain('Point DNS at the balancer')
            ->expectsOutputToContain('blacksmith:scale example.com --to=2')
            ->assertSuccessful();

        $this->assertSame(['203.0.113.1'], $dns->addresses('example.com'));
    }

    public function test_scale_adds_web_only_nodes()
    {
        $this->seedBlueprint();
        $this->blueprints()->saveEnv('example.com', "SESSION_DRIVER=file\n");
        $this->state()->putInstance('lb', ['server_id' => 900, 'profile' => 'balancer']);
        $this->state()->setSiteId('lb', 'example.com', 901);
        $this->state()->putPool('example.com', ['balancer' => 'lb', 'primary' => 'prod', 'nodes' => ['prod']]);

        $forge = $this->forge();
        $this->mockNewServer($forge, 1000);

        $forge->shouldReceive('sites')->with(1000)->andReturn([]);
        $forge->shouldReceive('createSite')->once()->andReturn($this->site(['id' => 1001, 'https' => false]));
        $forge->shouldReceive('syncAliases', 'updateSiteEnvironmentFile', 'updateSiteDeploymentScript')->once();
        $forge->shouldReceive('scheduledJobs')->with(1000)->andReturn([]);
        $forge->shouldReceive('backgroundProcesses')->with(1000)->andReturn([]);
        $forge->shouldReceive('redirectRules', 'securityRules')->andReturn([]);
        $forge->shouldReceive('deploySite')->once()->with(1000, 1001);
        $forge->shouldReceive('updateBalancingNodes')->once()->withArgs(fn ($server, $site, $nodes) => $server === 900
            && $site === 901
            && collect($nodes)->pluck('server_id')->all() === [100, 1000]);
        $forge->shouldNotReceive('createScheduledJob', 'createBackgroundProcess', 'obtainLetsEncryptCertificate');

        $this->artisan('blacksmith:scale', ['site' => 'example.com', '--to' => 2])
            ->expectsOutputToContain('SESSION_DRIVER=file')
            ->expectsOutputToContain('example.com is balanced across: prod, example-com-node-1')
            ->assertSuccessful();

        $this->assertSame('web', $this->state()->roleFor('example-com-node-1', 'example.com'));
    }

    public function test_scale_down_drains_then_deletes()
    {
        $this->seedBlueprint();
        $this->state()->putInstance('lb', ['server_id' => 900, 'profile' => 'balancer']);
        $this->state()->setSiteId('lb', 'example.com', 901);
        $this->state()->putInstance('example-com-node-1', ['server_id' => 1000, 'profile' => 'app', 'provider' => 'fake', 'provider_id' => 9001]);
        $this->state()->putPool('example.com', ['balancer' => 'lb', 'primary' => 'prod', 'nodes' => ['prod', 'example-com-node-1']]);

        $forge = $this->forge();
        $forge->shouldReceive('updateBalancingNodes')->once()->ordered()->withArgs(fn ($server, $site, $nodes) => $nodes[1]['down'] === true);
        $forge->shouldReceive('updateBalancingNodes')->once()->ordered()->withArgs(fn ($server, $site, $nodes) => count($nodes) === 1);
        $forge->shouldReceive('deleteServer')->once()->ordered()->with(1000);

        $this->artisan('blacksmith:scale', ['site' => 'example.com', '--to' => 1])
            ->expectsConfirmation('Delete the server(s) for example-com-node-1?', 'yes')
            ->assertSuccessful();

        $this->assertSame([9001], $this->provider->destroyed);
        $this->assertFalse($this->state()->hasInstance('example-com-node-1'));
        $this->assertSame(['prod'], $this->state()->pool('example.com')['nodes']);
    }

    public function test_the_primary_node_cannot_be_removed()
    {
        $this->seedBlueprint();
        $this->state()->putPool('example.com', ['balancer' => 'lb', 'primary' => 'prod', 'nodes' => ['prod']]);
        $this->forge();

        $this->artisan('blacksmith:scale', ['site' => 'example.com', '--remove' => ['prod']])
            ->expectsOutputToContain('is the primary node')
            ->assertFailed();

        $this->artisan('blacksmith:scale', ['site' => 'missing.com'])->assertFailed();
    }
}
