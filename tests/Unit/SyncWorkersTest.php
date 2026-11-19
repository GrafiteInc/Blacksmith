<?php

namespace Tests\Unit;

use Grafite\Blacksmith\Actions\SyncWorkers;
use Laravel\Forge\Resources\BackgroundProcess;
use Laravel\Forge\Resources\Site;
use Tests\TestCase;

class SyncWorkersTest extends TestCase
{
    public function test_a_worker_becomes_a_queue_work_command()
    {
        $this->assertSame(
            'php artisan queue:work database --queue=default --sleep=10 --timeout=60',
            SyncWorkers::toCommand([]),
        );

        $this->assertSame(
            'php8.4 artisan queue:work redis --queue=emails,default --sleep=3 --timeout=90 --tries=3 --force',
            SyncWorkers::toCommand(['connection' => 'redis', 'queue' => 'emails,default', 'sleep' => 3, 'timeout' => 90, 'tries' => 3, 'force' => true, 'php_version' => 'php84']),
        );
    }

    public function test_a_command_reads_back_into_the_same_worker()
    {
        $worker = SyncWorkers::normalize(['connection' => 'sqs', 'queue' => 'high', 'sleep' => 1, 'timeout' => 300, 'tries' => 5, 'processes' => 4, 'force' => true, 'php_version' => 'php83']);

        $process = new BackgroundProcess(['id' => 1, 'command' => SyncWorkers::toCommand($worker), 'processes' => 4]);

        $this->assertSame($worker, SyncWorkers::fromProcess($process));
        $this->assertTrue(SyncWorkers::isWorker($process));
        $this->assertFalse(SyncWorkers::isWorker(new BackgroundProcess(['command' => 'php artisan horizon'])));
    }

    public function test_the_payload_targets_the_site()
    {
        $site = new Site(['id' => 200, 'name' => 'example.com', 'user' => 'forge']);

        $this->assertSame([
            'name' => 'example.com redis:default',
            'site_id' => 200,
            'command' => 'php artisan queue:work redis --queue=default --sleep=10 --timeout=60',
            'user' => 'forge',
            'directory' => '/home/forge/example.com',
            'processes' => 2,
            'stopwaitsecs' => 10,
        ], SyncWorkers::payload($site, ['connection' => 'redis', 'processes' => 2, 'daemon' => true]));
    }
}
