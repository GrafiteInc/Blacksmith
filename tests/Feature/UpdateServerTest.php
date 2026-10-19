<?php

namespace Tests\Feature;

use Laravel\Forge\Resources\Job;
use Laravel\Forge\Resources\PHPVersion;
use Laravel\Forge\Resources\Worker;
use Tests\TestCase;

class UpdateServerTest extends TestCase
{
    /**
     * Forge reads for a server that matches the seeded blueprint exactly.
     */
    protected function mockMatchingServer($forge, ?array $sites = null)
    {
        $forge->shouldReceive('server')->with(100)->andReturn($this->server());
        $forge->shouldReceive('phpVersions')->with(100)->andReturn([new PHPVersion(['version' => 'php84'])]);
        $forge->shouldReceive('sites')->with(100)->andReturn($sites ?? [$this->site()]);
        $forge->shouldReceive('jobs')->with(100)->andReturn([new Job(['id' => 9, 'command' => 'php8.4 /home/forge/example.com/artisan schedule:run'])]);
        $forge->shouldReceive('siteEnvironmentFile')->andReturn("APP_NAME=Example\nAPP_URL=https://example.com\n");
        $forge->shouldReceive('siteDeploymentScript')->andReturn("cd /home/forge/example.com\ngit pull\n");
        $forge->shouldReceive('workers')->with(100, 200)->andReturn([new Worker(['id' => 1, 'connection' => 'redis', 'queue' => 'default', 'processes' => 2])]);
        $forge->shouldReceive('redirectRules')->andReturn([]);
        $forge->shouldReceive('securityRules')->andReturn([]);
    }

    public function test_only_the_differences_are_pushed()
    {
        $this->seedBlueprint();
        $this->blueprints()->saveEnvOverride('example.com', 'prod', "APP_DEBUG=false\n");

        $forge = $this->forge();
        $this->mockMatchingServer($forge);

        $forge->shouldReceive('updateServer')->once();
        $forge->shouldReceive('updateSiteEnvironmentFile')->once()->with(100, 200, "APP_NAME=Example\nAPP_URL=https://example.com\nAPP_DEBUG=false".PHP_EOL);
        $forge->shouldNotReceive('createSite', 'updateSiteDeploymentScript', 'createWorker', 'deleteWorker', 'createJob', 'obtainLetsEncryptCertificate');

        $this->artisan('blacksmith:update-server', ['instance' => 'prod'])
            ->expectsOutputToContain('prod example.com: Update environment')
            ->assertSuccessful();
    }

    public function test_a_missing_site_is_created_from_the_blueprint()
    {
        $this->seedBlueprint();
        $this->state()->putInstance('fresh', ['server_id' => 100, 'profile' => 'app', 'sites' => []]);
        $this->state()->forgetInstance('prod');

        $forge = $this->forge();
        $this->mockMatchingServer($forge, [$this->site(['id' => 1, 'name' => 'default'])]);
        $forge->shouldReceive('jobs')->with(100)->andReturn([]);

        $forge->shouldReceive('deleteSite')->once()->with(100, 1);
        $forge->shouldReceive('createSite')->once()->withArgs(fn ($server, $payload) => $payload['domain'] === 'example.com'
            && $payload['aliases'] === ['www.example.com']
            && $payload['php_version'] === 'php84')
            ->andReturn($this->site(['id' => 500, 'repository' => null, 'is_secured' => false]));
        $forge->shouldReceive('installGitRepositoryOnSite')->once()->with(100, 500, ['provider' => 'github', 'branch' => 'main', 'composer' => true, 'repository' => 'acme/example'], true);
        $forge->shouldReceive('updateSiteEnvironmentFile')->once();
        $forge->shouldReceive('updateSiteDeploymentScript')->once();
        $forge->shouldReceive('workers')->with(100, 500)->andReturn([]);
        $forge->shouldReceive('createWorker')->once()->withArgs(fn ($server, $site, $worker) => $site === 500 && $worker['processes'] === 2);
        $forge->shouldReceive('obtainLetsEncryptCertificate')->once()->with(100, 500, ['domains' => ['example.com', 'www.example.com']], false);
        $forge->shouldReceive('updateServer')->once();

        $this->artisan('blacksmith:update-server', ['instance' => 'fresh', '--site' => ['example.com']])->assertSuccessful();

        $this->assertSame(500, $this->state()->siteId('fresh', 'example.com'));
    }

    public function test_dry_run_makes_no_changes()
    {
        $this->seedBlueprint(['workers' => [['connection' => 'redis', 'queue' => 'high', 'processes' => 1]]]);

        $forge = $this->forge();
        $this->mockMatchingServer($forge);
        $forge->shouldNotReceive('updateServer', 'createWorker', 'deleteWorker', 'updateSiteEnvironmentFile');

        $this->artisan('blacksmith:update-server', ['--dry-run' => true])
            ->expectsOutputToContain('[plan] prod example.com: Create worker redis:high x1')
            ->expectsOutputToContain('change(s) planned')
            ->assertSuccessful();
    }

    public function test_only_limits_the_sections()
    {
        $this->seedBlueprint();
        $this->blueprints()->saveDeployScript('example.com', "new script\n");

        $forge = $this->forge();
        $this->mockMatchingServer($forge);
        $forge->shouldReceive('updateSiteDeploymentScript')->once()->with(100, 200, "new script\n");
        $forge->shouldNotReceive('updateServer', 'updateSiteEnvironmentFile');

        $this->artisan('blacksmith:update-server', ['instance' => 'prod', '--only' => 'deploy'])->assertSuccessful();

        $this->artisan('blacksmith:update-server', ['--only' => 'nope'])
            ->expectsOutput('Unknown sections: nope')
            ->assertFailed();
    }

    public function test_web_nodes_drop_the_scheduler_and_workers()
    {
        $this->seedBlueprint();
        $this->state()->putInstance('node', ['server_id' => 100, 'profile' => 'app']);
        $this->state()->setSiteId('node', 'example.com', 200);
        $this->state()->putPool('example.com', ['balancer' => 'lb', 'primary' => 'prod', 'nodes' => ['prod', 'node']]);

        $forge = $this->forge();
        $this->mockMatchingServer($forge);

        $forge->shouldReceive('deleteJob')->once()->with(100, 9);
        $forge->shouldReceive('deleteWorker')->once()->with(100, 200, 1);
        $forge->shouldNotReceive('createWorker', 'obtainLetsEncryptCertificate');

        $this->artisan('blacksmith:update-server', ['instance' => 'node', '--only' => 'scheduler,workers'])->assertSuccessful();
    }
}
