<?php

namespace Tests\Unit;

use Grafite\Blacksmith\Support\RemoteShell;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class RemoteShellTest extends TestCase
{
    public function test_the_default_port_adds_no_flag_and_another_port_is_passed_to_ssh()
    {
        Process::fake();

        (new RemoteShell('root', '/keys/id'))->run('203.0.113.1', 'true');
        (new RemoteShell('root', null, 15, 2222))->run('203.0.113.2', 'true');

        Process::assertRan(fn (PendingProcess $process) => end($process->command) === 'true'
            && in_array('root@203.0.113.1', $process->command, true)
            && ! in_array('-p', $process->command, true));

        Process::assertRan(fn (PendingProcess $process) => in_array('root@203.0.113.2', $process->command, true)
            && array_slice($process->command, array_search('-p', $process->command, true), 2) === ['-p', '2222']);
    }
}
