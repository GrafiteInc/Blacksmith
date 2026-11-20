<?php

namespace Tests\Unit;

use Grafite\Blacksmith\Support\Php;
use Tests\TestCase;

class PhpTest extends TestCase
{
    public function test_versions_are_named_the_way_forge_names_them()
    {
        $this->assertSame('php84', Php::name('8.4'));
        $this->assertSame('php84', Php::name('php8.4'));
        $this->assertSame('php84', Php::name('php84'));
        $this->assertSame('php85', Php::name('8.5'));
        $this->assertSame('php56-old', Php::name('php56-old'));
        $this->assertNull(Php::name(null));
    }

    public function test_binaries()
    {
        $this->assertSame('php8.4', Php::binary('php84'));
        $this->assertSame('php', Php::binary(null));
    }
}
