<?php

namespace Tests\Feature;

use Laravel\Forge\Resources\PHPVersion;
use Tests\TestCase;

class UpdateServerTest extends TestCase
{
    /**
     * Forge reads for a server that matches the seeded blueprint exactly.
     */
    protected function mockMatchingServer($forge, ?array $sites = null, ?array $processes = null, ?array $jobs = null)
    {
        $forge->shouldReceive('server')->with(100)->andReturn($this->server());
        $forge->shouldReceive('phpVersions')->with(100)->andReturn([new PHPVersion(['version' => 'php84'])]);
        $forge->shouldReceive('sites')->with(100)->andReturn($sites ?? [$this->site()]);
        $forge->shouldReceive('scheduledJobs')->with(100)->andReturn($jobs ?? [$this->schedulerJob(9)]);
        $forge->shouldReceive('siteEnvironmentFile')->andReturn("APP_NAME=Example\nAPP_URL=https://example.com\n");
        $forge->shouldReceive('siteDeploymentScript')->andReturn("cd /home/forge/example.com\ngit pull\n");
        $forge->shouldReceive('backgroundProcesses')->with(100)->andReturn($processes ?? [$this->worker(1, ['connection' => 'redis', 'processes' => 2])]);
        $forge->shouldReceive('redirectRules')->andReturn([]);
        $forge->shouldReceive('securityRules')->andReturn([]);
    }

    public function test_only_the_differences_are_pushed()
    {
        $this->seedBlueprint();
        $this->blueprints()->saveEnvOverride('example.com', 'prod', "APP_DEBUG=false\n");

        $forge = $this->forge();
        $this->mockMatchingServer($forge);

        $forge->shouldReceive('updatePhpLimits')->once()->with(100, 5, 30);
        $forge->shouldReceive('updateSiteEnvironmentFile')->once()->with(100, 200, "APP_NAME=Example\nAPP_URL=https://example.com\nAPP_DEBUG=false".PHP_EOL);
        $forge->shouldNotReceive('createSite', 'updateSite', 'syncAliases', 'updateSiteDeploymentScript', 'createBackgroundProcess', 'deleteBackgroundProcess', 'createScheduledJob', 'obtainLetsEncryptCertificate', 'installPHP', 'enableOPCache', 'disableOPCache');

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
        $this->mockMatchingServer($forge, [$this->site(['id' => 1, 'name' => 'default'])], [], []);

        $forge->shouldReceive('deleteSite')->once()->with(100, 1);
        $forge->shouldReceive('createSite')->once()->with(100, [
            'name' => 'example.com',
            'type' => 'laravel',
            'web_directory' => '/public',
            'is_isolated' => false,
            'php_version' => 'php84',
            'source_control_provider' => 'github',
            'repository' => 'acme/example',
            'branch' => 'main',
            'install_composer_dependencies' => true,
        ])->andReturn($this->site(['id' => 500, 'https' => false]));
        $forge->shouldReceive('syncAliases')->once()->with(100, 500, 'example.com', ['www.example.com']);
        $forge->shouldReceive('updateSiteEnvironmentFile')->once();
        $forge->shouldReceive('updateSiteDeploymentScript')->once();
        $forge->shouldReceive('createScheduledJob')->once()->with(100, [
            'name' => 'example.com scheduler',
            'command' => 'php8.4 /home/forge/example.com/artisan schedule:run',
            'frequency' => 'minutely',
            'user' => 'forge',
        ]);
        $forge->shouldReceive('createBackgroundProcess')->once()->withArgs(fn ($server, $payload) => $payload['site_id'] === 500 && $payload['processes'] === 2);
        $forge->shouldReceive('obtainLetsEncryptCertificate')->once()->with(100, 500, ['example.com', 'www.example.com']);
        $forge->shouldReceive('updatePhpLimits')->once();

        $this->artisan('blacksmith:update-server', ['instance' => 'fresh', '--site' => ['example.com']])->assertSuccessful();

        $this->assertSame(500, $this->state()->siteId('fresh', 'example.com'));
    }

    public function test_dry_run_makes_no_changes()
    {
        $this->seedBlueprint(['workers' => [['connection' => 'redis', 'queue' => 'high', 'processes' => 1]]]);

        $forge = $this->forge();
        $this->mockMatchingServer($forge);
        $forge->shouldNotReceive('updatePhpLimits', 'createBackgroundProcess', 'deleteBackgroundProcess', 'updateSiteEnvironmentFile');

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
        $forge->shouldNotReceive('updatePhpLimits', 'updateSiteEnvironmentFile');

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

        $forge->shouldReceive('deleteScheduledJob')->once()->with(100, 9);
        $forge->shouldReceive('deleteBackgroundProcess')->once()->with(100, 1);
        $forge->shouldNotReceive('createBackgroundProcess', 'obtainLetsEncryptCertificate');

        $this->artisan('blacksmith:update-server', ['instance' => 'node', '--only' => 'scheduler,workers'])->assertSuccessful();
    }

    public function test_site_settings_that_drifted_are_updated()
    {
        $this->seedBlueprint([
            'php_version' => 'php85',
            'aliases' => ['www.example.com', 'app.example.com'],
            'repository' => ['provider' => 'github', 'repository' => 'acme/example', 'branch' => 'release', 'composer' => true],
        ]);

        $forge = $this->forge();
        $this->mockMatchingServer($forge);

        $forge->shouldReceive('syncAliases')->once()->with(100, 200, 'example.com', ['www.example.com', 'app.example.com']);
        $forge->shouldReceive('updateSite')->once()->with(100, 200, ['php_version' => 'php85']);
        $forge->shouldReceive('updateSite')->once()->with(100, 200, ['repository_branch' => 'release']);

        $this->artisan('blacksmith:update-server', ['instance' => 'prod', '--only' => 'site,repository'])->assertSuccessful();
    }

    public function test_a_different_repository_is_reported_not_changed()
    {
        $this->seedBlueprint(['repository' => ['provider' => 'github', 'repository' => 'acme/other', 'branch' => 'main']]);

        $forge = $this->forge();
        $this->mockMatchingServer($forge);
        $forge->shouldNotReceive('updateSite');

        $this->artisan('blacksmith:update-server', ['instance' => 'prod', '--only' => 'repository'])
            ->expectsOutputToContain("Forge can't change a site's repository")
            ->assertSuccessful();
    }

    public function test_quick_deploy_and_security_rules_use_the_v2_endpoints()
    {
        $this->seedBlueprint([
            'quick_deploy' => true,
            'security' => [['name' => 'Admin', 'path' => '/admin', 'credentials' => [['username' => 'matt', 'password' => 'secret']]]],
        ]);

        $forge = $this->forge();
        $this->mockMatchingServer($forge);
        $forge->shouldReceive('securityRules')->andReturn([]);

        $forge->shouldReceive('enableQuickDeploy')->once()->with(100, 200);
        $forge->shouldReceive('createSecurityRule')->once()->with(100, 200, ['name' => 'Admin', 'path' => '/admin', 'credentials' => [['username' => 'matt', 'password' => 'secret']]]);

        $this->artisan('blacksmith:update-server', ['instance' => 'prod', '--only' => 'quick_deploy,security'])->assertSuccessful();
    }
}
