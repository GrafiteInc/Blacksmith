<?php

namespace Tests\Feature;

use Tests\TestCase;

class WorkersTest extends TestCase
{
    public function test_up_does_not_duplicate_matching_workers()
    {
        $this->seedBlueprint();

        $forge = $this->forge();
        $forge->shouldReceive('site')->with(100, 200)->andReturn($this->site());
        $forge->shouldReceive('backgroundProcesses')->with(100)->andReturn([$this->worker(1, ['connection' => 'redis', 'processes' => 2])]);
        $forge->shouldNotReceive('createBackgroundProcess', 'deleteBackgroundProcess');

        $this->artisan('blacksmith:workers', ['action' => 'up'])
            ->expectsOutput('Workers already match.')
            ->assertSuccessful();
    }

    public function test_up_replaces_workers_that_differ()
    {
        $this->seedBlueprint();

        $forge = $this->forge();
        $forge->shouldReceive('site')->with(100, 200)->andReturn($this->site());
        $forge->shouldReceive('backgroundProcesses')->with(100)->andReturn([
            $this->worker(1, ['connection' => 'database']),
            $this->worker(2, ['connection' => 'redis'], ['directory' => '/home/forge/other.com']),
            $this->worker(3, [], ['command' => 'node server.js']),
        ]);
        $forge->shouldReceive('deleteBackgroundProcess')->once()->with(100, 1);
        $forge->shouldReceive('createBackgroundProcess')->once()->withArgs(fn ($server, $payload) => $server === 100
            && $payload['site_id'] === 200
            && $payload['directory'] === '/home/forge/example.com'
            && $payload['command'] === 'php artisan queue:work redis --queue=default --sleep=10 --timeout=60'
            && $payload['processes'] === 2);

        $this->artisan('blacksmith:workers', ['action' => 'up', 'instance' => 'prod'])->assertSuccessful();
    }

    public function test_down_restart_and_status()
    {
        $this->seedBlueprint();

        $forge = $this->forge();
        $forge->shouldReceive('site')->with(100, 200)->andReturn($this->site());
        $forge->shouldReceive('backgroundProcesses')->with(100)->andReturn([$this->worker(1, ['connection' => 'redis', 'processes' => 2])]);
        $forge->shouldReceive('restartBackgroundProcess')->once()->with(100, 1);
        $forge->shouldReceive('deleteBackgroundProcess')->once()->with(100, 1);

        $this->artisan('blacksmith:workers', ['action' => 'restart'])->assertSuccessful();
        $this->artisan('blacksmith:workers', ['action' => 'down', '--site' => ['example.com']])->assertSuccessful();
        $this->artisan('blacksmith:workers', ['action' => 'status'])
            ->expectsTable(['Connection', 'Queue', 'Processes', 'Timeout', 'Tries', 'PHP', 'Status'], [['redis', 'default', 2, 60, '-', 'php', 'running']])
            ->assertSuccessful();
        $this->artisan('blacksmith:workers', ['action' => 'sideways'])->assertFailed();
    }

    public function test_a_site_missing_from_forge_fails()
    {
        $this->seedBlueprint();

        $this->forge()->shouldReceive('site')->with(100, 200)->andReturn(null);

        $this->artisan('blacksmith:workers', ['action' => 'status'])
            ->expectsOutputToContain('Site 200 was not found on Forge')
            ->assertFailed();
    }
}
