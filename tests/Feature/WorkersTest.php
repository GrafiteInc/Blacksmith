<?php

namespace Tests\Feature;

use Laravel\Forge\Resources\Worker;
use Tests\TestCase;

class WorkersTest extends TestCase
{
    public function test_up_does_not_duplicate_matching_workers()
    {
        $this->seedBlueprint();

        $forge = $this->forge();
        $forge->shouldReceive('workers')->with(100, 200)->andReturn([new Worker(['id' => 1, 'connection' => 'redis', 'queue' => 'default', 'processes' => 2])]);
        $forge->shouldNotReceive('createWorker', 'deleteWorker');

        $this->artisan('blacksmith:workers', ['action' => 'up'])
            ->expectsOutput('Workers already match.')
            ->assertSuccessful();
    }

    public function test_up_replaces_workers_that_differ()
    {
        $this->seedBlueprint();

        $forge = $this->forge();
        $forge->shouldReceive('workers')->with(100, 200)->andReturn([new Worker(['id' => 1, 'connection' => 'database', 'queue' => 'default', 'processes' => 1])]);
        $forge->shouldReceive('deleteWorker')->once()->with(100, 200, 1);
        $forge->shouldReceive('createWorker')->once()->withArgs(fn ($server, $site, $worker) => $worker['connection'] === 'redis');

        $this->artisan('blacksmith:workers', ['action' => 'up', 'instance' => 'prod'])->assertSuccessful();
    }

    public function test_down_restart_and_status()
    {
        $this->seedBlueprint();

        $forge = $this->forge();
        $forge->shouldReceive('workers')->with(100, 200)->andReturn([new Worker(['id' => 1, 'connection' => 'redis', 'queue' => 'default', 'processes' => 2, 'timeout' => 60, 'status' => 'running'])]);
        $forge->shouldReceive('restartWorker')->once()->with(100, 200, 1, false);
        $forge->shouldReceive('deleteWorker')->once()->with(100, 200, 1);

        $this->artisan('blacksmith:workers', ['action' => 'restart'])->assertSuccessful();
        $this->artisan('blacksmith:workers', ['action' => 'down', '--site' => ['example.com']])->assertSuccessful();
        $this->artisan('blacksmith:workers', ['action' => 'status'])
            ->expectsTable(['Connection', 'Queue', 'Processes', 'Timeout', 'Tries', 'PHP', 'Status'], [['redis', 'default', 2, 60, '-', '-', 'running']])
            ->assertSuccessful();
        $this->artisan('blacksmith:workers', ['action' => 'sideways'])->assertFailed();
    }
}
