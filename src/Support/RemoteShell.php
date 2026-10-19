<?php

namespace Grafite\Blacksmith\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class RemoteShell
{
    public function __construct(
        protected string $user = 'root',
        protected ?string $key = null,
        protected int $interval = 15,
    ) {}

    public function run(string $ip, string $command, int $timeout = 1800): ProcessResult
    {
        return Process::timeout($timeout)->run($this->command($ip, $command));
    }

    /**
     * New servers take a little while before sshd accepts connections.
     */
    public function waitUntilReachable(string $ip, int $timeout = 300): void
    {
        $reachable = Poll::until(fn () => $this->run($ip, 'true', 30)->successful(), $timeout, $this->interval);

        if (! $reachable) {
            throw new RuntimeException("Unable to connect to {$this->user}@{$ip} over SSH.");
        }
    }

    protected function command(string $ip, string $command): array
    {
        return array_merge(
            ['ssh', '-o', 'StrictHostKeyChecking=accept-new', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10'],
            $this->key ? ['-i', $this->key] : [],
            ["{$this->user}@{$ip}", $command],
        );
    }
}
