<?php

namespace Tests\Unit;

use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\ServerProviders\DigitalOceanProvider;
use Laravel\Forge\Resources\ServerCredential;
use Laravel\Forge\Resources\VPC;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DigitalOceanProviderTest extends TestCase
{
    protected function provider(array $defaults = []): DigitalOceanProvider
    {
        return new DigitalOceanProvider(null, array_replace(['region' => 'tor1', 'size' => 's-1vcpu-1gb', 'vpc_uuid' => false, 'backups' => false], $defaults));
    }

    protected static function credential(int $id, string $name, string $provider): ServerCredential
    {
        return new ServerCredential(['id' => $id, 'name' => $name, 'provider' => $provider]);
    }

    public function test_forge_gets_the_only_digitalocean_credential_and_the_regions_vpc()
    {
        $forge = Mockery::mock(ForgeGateway::class);
        $forge->shouldReceive('serverCredentials')->once()->andReturn([self::credential(7, 'AWS', 'AWS'), self::credential(33278, 'DigitalOcean', 'DigitalOcean')]);
        $forge->shouldReceive('vpcs')->once()->with(33278, 'tor1')->andReturn([new VPC(['id' => 'vpc-uuid', 'name' => 'default-tor1'])]);

        $this->assertSame([
            'provider' => 'ocean2',
            'credential_id' => 33278,
            'ocean2' => ['region_id' => 'tor1', 'size_id' => 's-1vcpu-1gb', 'vpc_uuid' => 'vpc-uuid', 'enable_weekly_backups' => false],
        ], $this->provider()->forgePayload($forge));
    }

    public function test_configured_values_and_profile_options_skip_the_lookups()
    {
        config(['blacksmith.forge_credential' => '42']);

        $forge = Mockery::mock(ForgeGateway::class);
        $forge->shouldNotReceive('serverCredentials', 'vpcs');

        $payload = $this->provider(['vpc_uuid' => 'configured-vpc', 'backups' => true])->forgePayload($forge, ['size' => 's-2vcpu-4gb']);

        $this->assertSame(42, $payload['credential_id']);
        $this->assertSame(['region_id' => 'tor1', 'size_id' => 's-2vcpu-4gb', 'vpc_uuid' => 'configured-vpc', 'enable_weekly_backups' => true], $payload['ocean2']);
    }

    public function test_several_vpcs_use_the_regions_default_one()
    {
        config(['blacksmith.forge_credential' => 1]);

        $forge = Mockery::mock(ForgeGateway::class);
        $forge->shouldReceive('vpcs')->with(1, 'tor1')->andReturn([new VPC(['id' => 'a', 'name' => 'private']), new VPC(['id' => 'b', 'name' => 'default-tor1'])]);

        $this->assertSame('b', $this->provider()->forgePayload($forge)['ocean2']['vpc_uuid']);
    }

    public function test_a_credential_or_vpc_that_cannot_be_chosen_says_what_to_set()
    {
        $forge = Mockery::mock(ForgeGateway::class);
        $forge->shouldReceive('serverCredentials')->andReturn([self::credential(1, 'Personal', 'DigitalOcean'), self::credential(2, 'Work', 'DigitalOcean')]);

        try {
            $this->provider()->forgePayload($forge);
            $this->fail('Several credentials should not be guessed between.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Your Forge organization has several DigitalOcean credentials (1 Personal, 2 Work), set BLACKSMITH_FORGE_CREDENTIAL to one of them.', $exception->getMessage());
        }

        config(['blacksmith.forge_credential' => 1]);
        $forge->shouldReceive('vpcs')->with(1, 'tor1')->andReturn([new VPC(['id' => 'a', 'name' => 'one']), new VPC(['id' => 'b', 'name' => 'two'])]);

        $this->expectExceptionMessage('tor1 has several VPCs (one, two), set BLACKSMITH_DO_VPC_UUID to one of them.');
        $this->provider()->forgePayload($forge);
    }
}
