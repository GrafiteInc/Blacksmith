<?php

namespace Grafite\Blacksmith\Support;

use Closure;
use Illuminate\Support\Sleep;

class Poll
{
    /**
     * Call $check every $interval seconds until it returns something truthy,
     * giving up (and returning null) after roughly $timeout seconds.
     */
    public static function until(Closure $check, int $timeout, int $interval): mixed
    {
        $attempts = max(1, intdiv($timeout, max(1, $interval)));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if ($result = $check()) {
                return $result;
            }

            if ($attempt < $attempts) {
                Sleep::for($interval)->seconds();
            }
        }

        return null;
    }
}
