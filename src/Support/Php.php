<?php

namespace Grafite\Blacksmith\Support;

class Php
{
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
