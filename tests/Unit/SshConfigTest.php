<?php

namespace Tests\Unit;

use Grafite\Blacksmith\Support\SshConfig;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;

class SshConfigTest extends TestCase
{
    protected string $dir;

    protected string $path;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/blacksmith-ssh-'.uniqid();
        $this->path = $this->dir.'/config';
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);
    }

    protected function config(?string $project = 'acme', int $port = 22, ?string $key = null, bool $enabled = true): SshConfig
    {
        return new SshConfig($this->path, $enabled, $project, 'forge', $port, $key);
    }

    public function test_the_alias_is_prefixed_with_the_project()
    {
        $this->assertSame('acme-web-1', $this->config()->alias('web-1'));
        $this->assertSame('web-1', $this->config(null)->alias('web-1'));
        $this->assertSame('My-App-web-1', $this->config('My App')->alias('web-1'));
    }

    public function test_it_creates_the_file_private_with_the_entry()
    {
        $this->config()->put('web-1', '203.0.113.1');

        $this->assertSame(implode(PHP_EOL, [
            '# BEGIN blacksmith acme-web-1',
            'Host acme-web-1',
            '    HostName 203.0.113.1',
            '    User forge',
            '# END blacksmith acme-web-1',
        ]).PHP_EOL, file_get_contents($this->path));

        $this->assertSame(0600, fileperms($this->path) & 0777);
        $this->assertTrue($this->config()->has('web-1'));
    }

    public function test_it_appends_after_existing_entries_and_leaves_them_alone()
    {
        mkdir($this->dir);
        $existing = "Host *\n    AddKeysToAgent yes\n\nHost personal\n    HostName 10.0.0.1\n    User forge\n";
        file_put_contents($this->path, $existing);
        chmod($this->path, 0644);

        $this->config()->put('web-1', '203.0.113.1');
        $this->config()->put('web-2', '203.0.113.2');

        $contents = file_get_contents($this->path);

        $this->assertStringStartsWith($existing."\n# BEGIN blacksmith acme-web-1\n", $contents);
        $this->assertStringContainsString("# END blacksmith acme-web-1\n\n# BEGIN blacksmith acme-web-2\n", $contents);
        $this->assertSame(0644, fileperms($this->path) & 0777);
    }

    public function test_updating_an_entry_keeps_its_place()
    {
        $this->config()->put('web-1', '203.0.113.1');
        $this->config()->put('web-2', '203.0.113.2');
        $this->config()->put('web-1', '198.51.100.9');

        $contents = file_get_contents($this->path);

        $this->assertStringNotContainsString('203.0.113.1', $contents);
        $this->assertSame(1, substr_count($contents, 'Host acme-web-1'));
        $this->assertLessThan(strpos($contents, 'acme-web-2'), strpos($contents, 'HostName 198.51.100.9'));
    }

    public function test_forget_removes_only_its_entry()
    {
        mkdir($this->dir);
        file_put_contents($this->path, "Host personal\n    HostName 10.0.0.1\n");

        $this->config()->put('web-1', '203.0.113.1');
        $this->config()->put('web-2', '203.0.113.2');
        $this->config()->forget('web-1');

        $this->assertSame("Host personal\n    HostName 10.0.0.1\n\n".$this->config()->block('web-2', '203.0.113.2'), file_get_contents($this->path));

        $this->config()->forget('web-2');
        $this->config()->forget('missing');

        $this->assertSame("Host personal\n    HostName 10.0.0.1\n", file_get_contents($this->path));
    }

    public function test_the_port_and_key_are_added_when_set()
    {
        $block = $this->config('acme', 2222, '~/.ssh/forge')->block('web-1', '203.0.113.1');

        $this->assertStringContainsString("    Port 2222\n", $block);
        $this->assertStringContainsString("    IdentityFile ~/.ssh/forge\n", $block);
    }

    public function test_disabled_it_writes_nothing()
    {
        $this->config(enabled: false)->put('web-1', '203.0.113.1');

        $this->assertFileDoesNotExist($this->path);
        $this->assertFalse($this->config(enabled: false)->has('web-1'));
    }
}
