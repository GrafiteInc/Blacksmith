<?php

namespace Grafite\Blacksmith\Tests\Unit;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class MakeServerTest extends TestCase
{
    public $command;

    public function test_make_server()
    {
        $this->markTestSkipped('Would require mocking DigitalOcean client.');

        config([
            'blacksmith.server_provider' => 'digitalocean',
            'blacksmith.server_provider_token' => 'fake-token',
            'blacksmith.server_provider_options.digitalocean.region' => 'tor1',
            'blacksmith.server_provider_options.digitalocean.size' => 's-1vcpu-1gb',
            'blacksmith.server_provider_options.digitalocean.image' => 'ubuntu-24-04-x64',
            'blacksmith.server_provider_options.digitalocean.backups' => false,
            'blacksmith.server_provider_options.digitalocean.ipv6' => false,
            'blacksmith.server_provider_options.digitalocean.monitoring' => true,
        ]);

        $response = Artisan::call('blacksmith:make-server', [
            'name' => 'test-server',
        ]);

        $this->assertEquals(0, $response);
    }
}
