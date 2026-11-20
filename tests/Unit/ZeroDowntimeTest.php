<?php

namespace Tests\Unit;

use Grafite\Blacksmith\Actions\PointDns;
use Grafite\Blacksmith\Actions\SyncSite;
use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Tests\TestCase;

class ZeroDowntimeTest extends TestCase
{
    protected const RELEASE_SCRIPT = "\$CREATE_RELEASE()\ncd \$FORGE_RELEASE_DIRECTORY\ncomposer install\n\$ACTIVATE_RELEASE()\n";

    public function test_the_create_payload_always_says_whether_the_site_uses_zero_downtime_deployments()
    {
        $blueprint = BlueprintRepository::defaultSite('example.com');

        $this->assertFalse(SyncSite::createPayload($blueprint)['zero_downtime_deployments'], 'Forge turns it on when it is left out.');
        $this->assertTrue(SyncSite::createPayload($blueprint, true)['zero_downtime_deployments']);
        $this->assertTrue(SyncSite::createPayload(array_replace($blueprint, ['zero_downtime' => true]))['zero_downtime_deployments']);
    }

    public function test_a_blueprint_without_the_setting_follows_its_deploy_script()
    {
        $this->blueprints()->saveSite('example.com', BlueprintRepository::defaultSite('example.com'));

        $this->blueprints()->saveDeployScript('example.com', "cd /home/forge/example.com\ngit pull\n");
        $this->assertFalse($this->blueprints()->zeroDowntime('example.com'));

        $this->blueprints()->saveDeployScript('example.com', self::RELEASE_SCRIPT);
        $this->assertTrue($this->blueprints()->zeroDowntime('example.com'));

        $this->blueprints()->saveSite('example.com', array_replace(BlueprintRepository::defaultSite('example.com'), ['zero_downtime' => false]));
        $this->assertFalse($this->blueprints()->zeroDowntime('example.com'), 'An explicit setting wins.');
    }

    public function test_the_scheduler_is_found_in_the_site_directory_or_its_current_release()
    {
        $this->assertTrue(SyncSite::isSchedulerFor('php8.4 /home/forge/example.com/artisan schedule:run', 'example.com'));
        $this->assertTrue(SyncSite::isSchedulerFor('php8.4 /home/forge/example.com/current/artisan schedule:run', 'example.com'));
        $this->assertFalse(SyncSite::isSchedulerFor('php8.4 /home/forge/other.com/current/artisan schedule:run', 'example.com'));
        $this->assertFalse(SyncSite::isSchedulerFor('php8.4 /home/forge/exampleXcom/artisan schedule:run', 'example.com'));
    }

    public function test_a_deploy_script_that_does_not_fit_the_site_is_reported()
    {
        $this->assertNull(SyncSite::deployScriptMismatch(true, self::RELEASE_SCRIPT));
        $this->assertNull(SyncSite::deployScriptMismatch(false, "git pull\n"));
        $this->assertNull(SyncSite::deployScriptMismatch(true, ''));
        $this->assertStringContainsString('deploys would run in place', SyncSite::deployScriptMismatch(true, "git pull\n"));
        $this->assertStringContainsString('"zero_downtime": true', SyncSite::deployScriptMismatch(false, self::RELEASE_SCRIPT));
    }

    public function test_only_unproxied_records_keep_the_old_server_reachable()
    {
        $this->assertSame(0, PointDns::drainSeconds(['proxied' => true, 'ttl' => 1]));
        $this->assertSame(3600, PointDns::drainSeconds(['proxied' => false, 'ttl' => 3600]));
        $this->assertSame(PointDns::AUTOMATIC_TTL, PointDns::drainSeconds(['proxied' => false, 'ttl' => 1]));
        $this->assertSame(PointDns::AUTOMATIC_TTL, PointDns::drainSeconds(['proxied' => null, 'ttl' => null]));
    }
}
