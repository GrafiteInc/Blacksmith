<?php

namespace Grafite\Blacksmith\Tests\Unit;

use Grafite\Blacksmith\Commands\Backup;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

class BackupTest extends TestCase
{
    public $command;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->command = app(Backup::class);
    }

    public function test_backup()
    {
        $this->markTestIncomplete('Not sure how to test yet.');

        $response = $this->command->handle();

        $this->assertEquals(0, $response);
    }
}
