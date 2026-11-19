<?php

namespace Tests\Unit;

use Grafite\Blacksmith\Actions\SyncSite;
use Grafite\Blacksmith\Actions\SyncWorkers;
use Grafite\Blacksmith\BlacksmithProvider;
use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Laravel\Forge\Resources\Site;
use Tests\TestCase;

class DefaultsTest extends TestCase
{
    public function test_new_blueprints_start_from_the_config_defaults()
    {
        config([
            'blacksmith.defaults.server.php_version' => 'php85',
            'blacksmith.defaults.server.max_upload_size' => 100,
            'blacksmith.defaults.site.project_type' => 'statamic',
            'blacksmith.defaults.site.directory' => '/web',
        ]);

        $profile = BlueprintRepository::defaultProfile('worker');
        $site = BlueprintRepository::defaultSite('example.com');

        $this->assertSame('worker', $profile['type']);
        $this->assertSame('php85', $profile['php_version']);
        $this->assertSame(100, $profile['max_upload_size']);
        $this->assertSame([], $profile['provider']);
        $this->assertSame('statamic', $site['project_type']);
        $this->assertSame('/web', $site['directory']);
        $this->assertSame('statamic', SyncSite::siteType(null));
    }

    public function test_workers_repositories_and_users_use_the_config_defaults()
    {
        config([
            'blacksmith.defaults.user' => 'deploy',
            'blacksmith.defaults.worker.connection' => 'redis',
            'blacksmith.defaults.worker.tries' => 3,
            'blacksmith.defaults.repository.provider' => 'gitlab',
            'blacksmith.defaults.repository.branch' => 'production',
        ]);

        $this->assertSame('php artisan queue:work redis --queue=default --sleep=10 --timeout=60 --tries=3', SyncWorkers::toCommand([]));
        $this->assertSame('deploy', SyncWorkers::payload(new Site(['id' => 1, 'name' => 'example.com']), [])['user']);
        $this->assertSame('/home/deploy/example.com', SyncWorkers::payload(new Site(['id' => 1, 'name' => 'example.com']), [])['directory']);
        $this->assertSame(['provider' => 'gitlab', 'repository' => 'acme/example', 'branch' => 'production', 'composer' => true], SyncSite::repository(['repository' => 'acme/example']));
    }

    public function test_a_published_config_still_gets_new_defaults()
    {
        config(['blacksmith.defaults' => ['site' => ['project_type' => 'php']]]);

        (new BlacksmithProvider($this->app))->register();

        $this->assertSame('php', config('blacksmith.defaults.site.project_type'));
        $this->assertSame('/public', config('blacksmith.defaults.site.directory'));
        $this->assertSame('round_robin', config('blacksmith.defaults.balancer.method'));
        $this->assertSame('forge', config('blacksmith.defaults.user'));
    }
}
