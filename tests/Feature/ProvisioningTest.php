<?php

namespace Tests\Feature;

use Grafite\Blacksmith\ServerProviders\ServerProvider;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Laravel\Forge\Resources\PHPVersion;
use Laravel\Forge\Resources\Server;
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

    protected function mockNewServer($forge, int $id, string $type = 'app')
    {
        $forge->shouldReceive('createServer')->once()->ordered()->withArgs(fn ($payload) => $payload['provider'] === 'custom' && $payload['type'] === $type)
            ->andReturnUsing(fn ($payload) => new Server(['id' => $id, 'name' => $payload['name'], 'type' => $type, 'provision_command' => "curl forge/{$id} | bash"]));
        $forge->shouldReceive('server')->with($id)->andReturn($this->server(['id' => $id, 'is_ready' => true, 'type' => $type]));
        $forge->shouldReceive('phpVersions')->with($id)->andReturn([new PHPVersion(['version' => 'php84'])]);
        $forge->shouldReceive('updateServer')->with($id, \Mockery::any());
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
        $forge->shouldNotReceive('createServer');

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
        $forge->shouldReceive('createSite')->once()->andReturn($this->site(['id' => 801, 'repository' => null, 'is_secured' => false]));
        $forge->shouldReceive('installGitRepositoryOnSite')->once();
        $forge->shouldReceive('updateSiteEnvironmentFile')->once();
        $forge->shouldReceive('updateSiteDeploymentScript')->once();
        $forge->shouldReceive('jobs')->with(800)->andReturn([]);
        $forge->shouldReceive('createJob')->once()->withArgs(fn ($server, $job) => $job['command'] === 'php8.4 /home/forge/example.com/artisan schedule:run');
        $forge->shouldReceive('workers')->with(800, 801)->andReturn([]);
        $forge->shouldReceive('createWorker')->once();
        $forge->shouldReceive('obtainLetsEncryptCertificate')->once();
        $forge->shouldReceive('redirectRules', 'securityRules')->andReturn([]);
        $forge->shouldReceive('deploySite')->once()->with(800, 801, false);

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
        $forge->shouldReceive('createSite')->once()->withArgs(fn ($server, $payload) => ! isset($payload['php_version']))
            ->andReturn($this->site(['id' => 901, 'is_secured' => false]));
        $forge->shouldReceive('redirectRules', 'securityRules')->andReturn([]);
        $forge->shouldReceive('updateNodeBalancingConfiguration')->once()->with(900, 901, [
            'servers' => [['id' => 100, 'weight' => 1, 'down' => false, 'backup' => false, 'port' => 80]],
            'method' => 'least_conn',
        ]);
        $forge->shouldNotReceive('obtainLetsEncryptCertificate', 'createWorker', 'updateSiteEnvironmentFile');

        $this->artisan('blacksmith:balancer', ['name' => 'lb', '--method' => 'least_conn'])
            ->expectsOutputToContain('Point DNS at the balancer')
            ->assertSuccessful();

        $pool = $this->state()->pool('example.com');

        $this->assertSame('lb', $pool['balancer']);
        $this->assertSame('prod', $pool['primary']);
        $this->assertSame(['prod'], $pool['nodes']);
        $this->assertSame('loadbalancer', $this->blueprints()->profile('balancer')['type']);
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
        $forge->shouldReceive('createSite')->once()->andReturn($this->site(['id' => 1001, 'repository' => null, 'is_secured' => false]));
        $forge->shouldReceive('installGitRepositoryOnSite', 'updateSiteEnvironmentFile', 'updateSiteDeploymentScript')->once();
        $forge->shouldReceive('jobs')->with(1000)->andReturn([]);
        $forge->shouldReceive('workers')->with(1000, 1001)->andReturn([]);
        $forge->shouldReceive('redirectRules', 'securityRules')->andReturn([]);
        $forge->shouldReceive('deploySite')->once()->with(1000, 1001, false);
        $forge->shouldReceive('updateNodeBalancingConfiguration')->once()->withArgs(fn ($server, $site, $data) => $server === 900
            && $site === 901
            && collect($data['servers'])->pluck('id')->all() === [100, 1000]);
        $forge->shouldNotReceive('createJob', 'createWorker', 'obtainLetsEncryptCertificate');

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
        $forge->shouldReceive('updateNodeBalancingConfiguration')->once()->ordered()->withArgs(fn ($server, $site, $data) => $data['servers'][1]['down'] === true);
        $forge->shouldReceive('updateNodeBalancingConfiguration')->once()->ordered()->withArgs(fn ($server, $site, $data) => count($data['servers']) === 1);
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
