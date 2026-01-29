<?php

namespace Grafite\Blacksmith\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SetupTest extends TestCase
{
    public $command;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    public function test_setup()
    {
        // $this->markTestIncomplete('Not sure how to test yet.');

        Artisan::call('blacksmith:setup', [
            '--domain' => 'test.com',
        ]);

        $this->assertTrue(file_exists(base_path('.blacksmith/config.json')));
        $this->assertTrue(file_exists(base_path('.blacksmith/test.com.env')));
        $this->assertTrue(file_exists(base_path('.blacksmith/test.com.deploy')));

        @array_map('unlink', glob(base_path('.blacksmith').'/*'));
        @array_map('unlink', glob(base_path('.blacksmith').'/.*'));
        rmdir(base_path('.blacksmith'));
    }
}
