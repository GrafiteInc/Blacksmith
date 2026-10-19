<?php

namespace Tests\Unit;

use Grafite\Blacksmith\Support\EnvFile;
use Grafite\Blacksmith\Support\Php;
use PHPUnit\Framework\TestCase;

class EnvFileTest extends TestCase
{
    public function test_parse_ignores_comments_and_blank_lines()
    {
        $values = EnvFile::parse("# comment\nAPP_NAME=\"Example\"\n\nexport APP_ENV=production\nBROKEN LINE\n");

        $this->assertSame(['APP_NAME' => '"Example"', 'APP_ENV' => 'production'], $values);
    }

    public function test_merge_replaces_in_place_and_appends_new_keys()
    {
        $merged = EnvFile::merge("# App\nAPP_NAME=Example\nAPP_URL=https://example.com\n", "APP_URL=https://staging.example.com\nNEW_KEY=1\n");

        $this->assertSame("# App\nAPP_NAME=Example\nAPP_URL=https://staging.example.com\nNEW_KEY=1".PHP_EOL, $merged);
    }

    public function test_merge_without_overrides_returns_base()
    {
        $this->assertSame("A=1\n", EnvFile::merge("A=1\n", "# nothing\n"));
    }

    public function test_diff_only_keeps_changed_and_new_keys()
    {
        $diff = EnvFile::diff("A=1\nB=2\n", "A=1\nB=3\nC=4\n");

        $this->assertSame('B=3'.PHP_EOL.'C=4'.PHP_EOL, $diff);
        $this->assertSame('', EnvFile::diff("A=1\n", "A=1\n"));
    }

    public function test_scale_warnings()
    {
        $warnings = EnvFile::scaleWarnings("SESSION_DRIVER=file\nCACHE_STORE=redis\nDB_HOST=127.0.0.1\nFILESYSTEM_DISK=local\n");

        $this->assertCount(3, $warnings);
        $this->assertStringContainsString('SESSION_DRIVER', $warnings[0]);
        $this->assertSame([], EnvFile::scaleWarnings("SESSION_DRIVER=redis\nDB_HOST=db.internal\n"));
    }

    public function test_php_binary()
    {
        $this->assertSame('php8.4', Php::binary('php84'));
        $this->assertSame('php8.10', Php::binary('php810'));
        $this->assertSame('php', Php::binary('php'));
        $this->assertSame('php', Php::binary(null));
    }
}
