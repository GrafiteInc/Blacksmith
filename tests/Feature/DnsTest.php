<?php

namespace Tests\Feature;

use Tests\TestCase;

class DnsTest extends TestCase
{
    public function test_records_on_a_known_server_are_moved_and_missing_ones_created()
    {
        $this->seedBlueprint();
        $this->state()->putInstance('staging', ['server_id' => 300, 'ip_address' => '10.0.0.3', 'profile' => 'app']);
        $this->state()->setSiteId('staging', 'example.com', 301);

        $dns = $this->dns(['example.com' => [['content' => '10.0.0.1']]]);

        $this->artisan('blacksmith:dns', ['instance' => 'staging'])
            ->expectsOutputToContain('DNS example.com: Point A record 10.0.0.1 (prod) → 10.0.0.3 (staging)')
            ->expectsOutputToContain('DNS www.example.com: Create A record → 10.0.0.3')
            ->expectsOutputToContain('DNS is pointed at staging (10.0.0.3)')
            ->assertSuccessful();

        $this->assertSame(['update example.com example.com-0 10.0.0.3', 'create www.example.com 10.0.0.3'], $dns->calls);
    }

    public function test_records_blacksmith_does_not_manage_need_force()
    {
        $this->seedBlueprint(['aliases' => []]);
        $dns = $this->dns(['example.com' => [['content' => '198.51.100.7']]]);

        $this->artisan('blacksmith:dns', ['instance' => 'prod'])
            ->expectsOutputToContain('points at 198.51.100.7, which Blacksmith doesn\'t manage')
            ->assertSuccessful();

        $this->assertSame([], $dns->calls);

        $this->artisan('blacksmith:dns', ['instance' => 'prod', '--force' => true])->assertSuccessful();

        $this->assertSame(['10.0.0.1'], $dns->addresses('example.com'));
    }

    public function test_cnames_and_matching_records_are_left_alone()
    {
        $this->seedBlueprint(['aliases' => ['www.example.com', 'shop.example.com']]);

        $dns = $this->dns([
            'example.com' => [['content' => '10.0.0.1']],
            'www.example.com' => [['type' => 'CNAME', 'content' => 'example.com']],
            'shop.example.com' => [['type' => 'CNAME', 'content' => 'shops.myshopify.com']],
        ]);

        $this->artisan('blacksmith:dns', ['instance' => 'prod'])
            ->expectsOutputToContain('DNS example.com: already points at 10.0.0.1')
            ->expectsOutputToContain('DNS www.example.com: CNAME follows example.com')
            ->expectsOutputToContain('DNS shop.example.com: is a CNAME to shops.myshopify.com, which Blacksmith doesn\'t manage')
            ->assertSuccessful();

        $this->assertSame([], $dns->calls);
    }

    public function test_round_robin_records_and_opted_out_sites_are_skipped()
    {
        $this->seedBlueprint(['aliases' => []]);
        $dns = $this->dns(['example.com' => [['content' => '10.0.0.1'], ['content' => '10.0.0.2']]]);

        $this->artisan('blacksmith:dns', ['instance' => 'prod'])
            ->expectsOutputToContain('has 2 A records (10.0.0.1, 10.0.0.2)')
            ->assertSuccessful();

        $this->seedBlueprint(['aliases' => [], 'dns' => false]);

        $this->artisan('blacksmith:dns', ['instance' => 'prod'])
            ->expectsOutputToContain('DNS is managed by hand')
            ->assertSuccessful();

        $this->assertSame([], $dns->calls);
    }

    public function test_a_pooled_site_points_at_its_balancer()
    {
        $this->seedBlueprint();
        $this->state()->putInstance('lb', ['server_id' => 900, 'ip_address' => '10.0.0.9', 'profile' => 'balancer']);
        $this->state()->setSiteId('lb', 'example.com', 901);
        $this->state()->putPool('example.com', ['balancer' => 'lb', 'primary' => 'prod', 'nodes' => ['prod']]);

        $dns = $this->dns(['example.com' => [['content' => '10.0.0.1']]]);

        $this->artisan('blacksmith:dns', ['instance' => 'prod'])
            ->expectsOutputToContain('example.com is behind the load balancer lb, point DNS at it with: php artisan blacksmith:dns lb')
            ->assertSuccessful();

        $this->assertSame([], $dns->calls);

        $this->artisan('blacksmith:dns', ['instance' => 'lb'])->assertSuccessful();

        $this->assertSame(['10.0.0.9'], $dns->addresses('example.com'));
    }

    public function test_dns_needs_a_provider()
    {
        $this->seedBlueprint();

        $this->artisan('blacksmith:dns', ['instance' => 'prod'])
            ->expectsOutputToContain('DNS is not configured')
            ->assertFailed();
    }
}
