<?php

namespace Tests\Feature;

use Grafite\Blacksmith\ServerProviders\ServerProvider;
use Grafite\Blacksmith\Support\SshConfig;
use Illuminate\Support\Facades\Process;
use Laravel\Forge\Resources\Deployment;
use Laravel\Forge\Resources\Recipe;
use Laravel\Forge\Resources\RecipeRun;
use RuntimeException;
use Tests\Fakes\FakeServerProvider;
use Tests\TestCase;

class ServerAccessTest extends TestCase
{
    protected FakeServerProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new FakeServerProvider;
        $this->app->instance(ServerProvider::class, $this->provider);

        config(['blacksmith.sudo_password' => true, 'blacksmith.project' => 'acme']);

        Process::fake();
    }

    protected function sshConfig(): string
    {
        return is_file($this->root.'/ssh_config') ? file_get_contents($this->root.'/ssh_config') : '';
    }

    protected function mockSudoRecipe($forge, int $serverId, int $recipeId = 55): void
    {
        $forge->shouldReceive('createRecipe')->once()->withArgs(fn ($name, $user) => $user === 'root')->andReturn(new Recipe(['id' => $recipeId]));
        $forge->shouldReceive('runRecipe')->once()->with($recipeId, $serverId);
        $forge->shouldReceive('recipeRuns')->with($recipeId)->andReturn([new RecipeRun(['id' => 1, 'server_id' => $serverId, 'status' => 'finished', 'finished_at' => '2026-11-19 10:00:00'])]);
        $forge->shouldReceive('deleteRecipe')->once()->with($recipeId);
    }

    public function test_make_server_reports_the_login_details_and_adds_an_ssh_entry()
    {
        $forge = $this->forge();
        $this->mockNewServer($forge, 700);
        $this->mockSudoRecipe($forge, 700);

        $this->artisan('blacksmith:make-server', ['instance' => 'worker-1'])
            ->expectsOutputToContain('worker-1: Setting a new sudo password for forge')
            ->expectsOutputToContain('the sudo password is not stored anywhere')
            ->expectsOutputToContain('Name:           acme-worker-1')
            ->expectsOutputToContain('IP Address:     203.0.113.1')
            ->expectsOutputToContain('Username:       forge')
            ->expectsOutputToContain('Sudo Password:  ')
            ->expectsOutputToContain('SSH:            ssh acme-worker-1')
            ->assertSuccessful();

        $this->assertStringContainsString("Host acme-worker-1\n    HostName 203.0.113.1\n    User forge\n", $this->sshConfig());
        $this->assertStringNotContainsString('password', strtolower(file_get_contents($this->root.'/state.json')));
    }

    public function test_a_failed_sudo_password_only_warns()
    {
        $forge = $this->forge();
        $this->mockNewServer($forge, 700);
        $forge->shouldReceive('createRecipe')->once()->andThrow(new RuntimeException('Forge rejected creating recipe: user: The user field is invalid.'));

        $this->artisan('blacksmith:make-server', ['instance' => 'worker-1'])
            ->expectsOutputToContain('Set one later with: php artisan blacksmith:sudo-password worker-1')
            ->expectsOutputToContain('Sudo Password:  (not set, run php artisan blacksmith:sudo-password)')
            ->assertSuccessful();

        $this->assertStringContainsString('Host acme-worker-1', $this->sshConfig());
    }

    public function test_the_report_is_printed_even_when_a_later_step_fails()
    {
        $this->seedBlueprint();

        $forge = $this->forge();
        $this->mockNewServer($forge, 800);
        $this->mockSudoRecipe($forge, 800);
        $forge->shouldReceive('sites')->with(800)->andReturn([]);
        $forge->shouldReceive('createSite')->once()->andReturn($this->site(['id' => 801, 'https' => false]));
        $forge->shouldReceive('syncAliases', 'updateSiteDeploymentScript', 'updateSiteEnvironmentFile')->once();
        $forge->shouldReceive('scheduledJobs')->with(800)->andReturn([]);
        $forge->shouldReceive('backgroundProcesses')->with(800)->andReturn([]);
        $forge->shouldReceive('redirectRules', 'securityRules')->andReturn([]);
        $forge->shouldReceive('deployAndWait')->once()->andThrow(new RuntimeException('Deployment 1 ended as failed.'));

        $this->artisan('blacksmith:replace', ['instance' => 'prod'])
            ->expectsOutputToContain('Deployment 1 ended as failed.')
            ->expectsOutputToContain('Name:           acme-prod')
            ->expectsOutputToContain('SSH:            ssh acme-prod-next (ssh acme-prod after cutover)')
            ->assertFailed();
    }

    public function test_cutover_moves_the_ssh_entry_to_the_new_server_and_finish_removes_the_old_one()
    {
        $this->seedBlueprint();
        $this->state()->putInstance('prod-next', ['server_id' => 800, 'ip_address' => '203.0.113.1', 'profile' => 'app', 'replaces' => 'prod']);
        $this->state()->setSiteId('prod-next', 'example.com', 801);

        $ssh = $this->app->make(SshConfig::class);
        $ssh->put('prod', '10.0.0.1');
        $ssh->put('prod-next', '203.0.113.1');

        $forge = $this->forge();
        $forge->shouldReceive('latestDeployment')->with(800, 801)->andReturn(new Deployment(['id' => 1, 'status' => 'finished']));
        $forge->shouldReceive('site')->with(800, 801)->andReturn($this->site(['id' => 801, 'https' => true]));
        $forge->shouldReceive('sites')->with(100)->andReturn([$this->site()]);
        $forge->shouldReceive('scheduledJobs')->with(100)->andReturn([]);
        $forge->shouldReceive('backgroundProcesses')->with(100)->andReturn([]);
        $forge->shouldReceive('sites')->with(800)->andReturn([$this->site(['id' => 801, 'https' => false])]);
        $forge->shouldReceive('scheduledJobs')->with(800)->andReturn([]);
        $forge->shouldReceive('backgroundProcesses')->with(800)->andReturn([]);
        $forge->shouldReceive('createScheduledJob', 'createBackgroundProcess', 'obtainLetsEncryptCertificate')->once();

        $this->artisan('blacksmith:replace', ['instance' => 'prod', '--cutover' => true])
            ->expectsConfirmation('Delete the old server prod-old (10.0.0.1) now?', 'no')
            ->assertSuccessful();

        $config = $this->sshConfig();
        $this->assertStringContainsString("Host acme-prod\n    HostName 203.0.113.1\n", $config);
        $this->assertStringContainsString("Host acme-prod-old\n    HostName 10.0.0.1\n", $config);
        $this->assertStringNotContainsString('acme-prod-next', $config);

        $forge->shouldReceive('deleteServer')->once()->with(100);

        $this->artisan('blacksmith:replace', ['instance' => 'prod', '--finish' => true, '--force' => true])->assertSuccessful();

        $this->assertStringNotContainsString('acme-prod-old', $this->sshConfig());
        $this->assertStringContainsString('Host acme-prod', $this->sshConfig());
    }

    public function test_sudo_password_resets_an_existing_instance()
    {
        $this->seedBlueprint();

        $forge = $this->forge();
        $this->mockSudoRecipe($forge, 100);

        $this->artisan('blacksmith:sudo-password', ['instance' => 'prod'])
            ->expectsOutputToContain('prod: Setting a new sudo password for forge')
            ->expectsOutputToContain('Name:           acme-prod')
            ->expectsOutputToContain('IP Address:     10.0.0.1')
            ->assertSuccessful();

        $this->assertStringContainsString("Host acme-prod\n    HostName 10.0.0.1\n", $this->sshConfig());
    }

    public function test_sudo_password_fails_for_unknown_instances_and_failed_runs()
    {
        $this->seedBlueprint();

        $forge = $this->forge();
        $forge->shouldReceive('createRecipe')->once()->andReturn(new Recipe(['id' => 55]));
        $forge->shouldReceive('runRecipe')->once();
        $forge->shouldReceive('recipeRuns')->andReturn([new RecipeRun(['id' => 1, 'server_id' => 100, 'status' => 'failed'])]);
        $forge->shouldReceive('deleteRecipe')->once();

        $this->artisan('blacksmith:sudo-password', ['instance' => 'prod'])
            ->expectsOutputToContain('ended as failed')
            ->assertFailed();

        $this->artisan('blacksmith:sudo-password', ['instance' => 'missing'])->assertFailed();
    }

    public function test_the_ssh_config_can_be_turned_off()
    {
        config(['blacksmith.ssh_config.enabled' => false, 'blacksmith.sudo_password' => false]);

        $forge = $this->forge();
        $this->mockNewServer($forge, 700);
        $forge->shouldNotReceive('createRecipe');

        $this->artisan('blacksmith:make-server', ['instance' => 'worker-1'])
            ->expectsOutputToContain('Name:           acme-worker-1')
            ->doesntExpectOutputToContain('SSH:')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->root.'/ssh_config');
    }
}
