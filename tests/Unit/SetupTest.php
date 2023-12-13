<?php

namespace Grafite\Blacksmith\Tests\Unit;

use Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;

class SetupTest extends TestCase
{
    public $command;

    public function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    public function testSetup()
    {
        // $this->markTestIncomplete('Not sure how to test yet.');

        Artisan::call('blacksmith:setup', [
            'domain' => 'test.com',
            '--project' => 'test',
        ]);

        $this->assertTrue(file_exists(base_path('.blacksmith/config.json')));
        $this->assertTrue(file_exists(base_path('.blacksmith/.env.production')));
        $this->assertTrue(file_exists(base_path('.blacksmith/.deploy.production')));

        @array_map('unlink', glob(base_path('.blacksmith')."/*"));
        @array_map('unlink', glob(base_path('.blacksmith')."/.*"));
        rmdir(base_path('.blacksmith'));
    }
}
