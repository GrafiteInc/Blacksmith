<?php

namespace Grafite\Blacksmith\Support;

class Php
{
    /**
     * Forge's name for a version (php84), from that name, the 8.4 its PHP versions list
     * returns, or the php8.4 binary. Anything else is returned unchanged.
     */
    public static function name(?string $version): ?string
    {
        if (preg_match('/^(?:php)?(\d)\.?(\d+)$/', (string) $version, $matches)) {
            return "php{$matches[1]}{$matches[2]}";
        }

        return $version;
    }

    /**
     * Forge names versions like php84, the binary on the server is php8.4.
     */
    public static function binary(?string $version): string
    {
        if (preg_match('/^php(\d)(\d+)$/', (string) $version, $matches)) {
            return "php{$matches[1]}.{$matches[2]}";
        }

        return 'php';
    }
}
