<?php

namespace Grafite\Blacksmith\Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Grafite\Blacksmith\Commands\Backup;

class BackupTest extends TestCase
{
    public $command;

    public function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $this->command = app(Backup::class);
    }

    public function testBackup()
    {
        $this->markTestIncomplete('Not sure how to test yet.');

        $response = $this->command->handle();

        $this->assertEquals(0, $response);
    }
}
