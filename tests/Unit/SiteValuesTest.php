<?php

namespace Tests\Unit;

use Grafite\Blacksmith\Actions\SyncSite;
use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\Support\Php;
use Tests\TestCase;

/**
 * Forge's API v2 returns sites with labels and full paths, but is sent slugs and relative paths.
 */
class SiteValuesTest extends TestCase
{
    public function test_a_blueprint_localized_from_forge_creates_a_valid_site()
    {
        $blueprint = array_replace(BlueprintRepository::defaultSite('grafene.app'), [
            'directory' => '/home/forge/grafene.app/public',
            'project_type' => 'Custom',
            'php_version' => 'PHP 8.4',
            'repository' => ['provider' => 'GitHub', 'repository' => 'mlantz/grafene', 'branch' => 'main', 'composer' => false],
        ]);

        $this->assertSame([
            'name' => 'grafene.app',
            'domain_mode' => 'custom',
            'www_redirect_type' => 'none',
            'allow_wildcard_subdomains' => false,
            'type' => 'custom',
            'web_directory' => '/public',
            'is_isolated' => false,
            'php_version' => 'php84',
            'zero_downtime_deployments' => false,
            'source_control_provider' => 'github',
            'repository' => 'mlantz/grafene',
            'branch' => 'main',
            'install_composer_dependencies' => false,
        ], SyncSite::createPayload($blueprint));
    }

    public function test_site_types_are_slugs()
    {
        $this->assertSame('custom', SyncSite::siteType('Custom'));
        $this->assertSame('laravel', SyncSite::siteType('laravel'));
        $this->assertSame('static-html', SyncSite::siteType('Static HTML'));
        $this->assertSame('static-html', SyncSite::siteType('html'));
        $this->assertSame('nextjs', SyncSite::siteType('Next.js'));
    }

    public function test_web_directories_are_relative_to_the_site()
    {
        $this->assertSame('/public', SyncSite::webDirectory('/public', 'example.com'));
        $this->assertSame('/public', SyncSite::webDirectory('/home/forge/example.com/public', 'example.com'));
        $this->assertSame('/public', SyncSite::webDirectory('/home/deploy/example.com/current/public/', 'example.com'));
        $this->assertSame('/web', SyncSite::webDirectory('/srv/app/web', 'example.com', '/srv/app'));
        $this->assertSame('/', SyncSite::webDirectory('/home/forge/example.com', 'example.com'));
        $this->assertSame('/home/forge/other.com/public', SyncSite::webDirectory('/home/forge/other.com/public', 'example.com'));
        $this->assertNull(SyncSite::webDirectory(null, 'example.com'));
    }

    public function test_source_control_providers_and_php_labels()
    {
        $this->assertSame('github', SyncSite::sourceControlProvider('GitHub'));
        $this->assertSame('gitlab-custom', SyncSite::sourceControlProvider('GitLab Custom'));
        $this->assertSame('php84', Php::name('PHP 8.4'));
        $this->assertSame('php85', Php::name('php 8.5'));
    }
}
