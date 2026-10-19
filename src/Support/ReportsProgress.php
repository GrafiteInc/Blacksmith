<?php

namespace Grafite\Blacksmith\Support;

use Closure;

trait ReportsProgress
{
    protected ?Closure $reporter = null;

    public function reportUsing(?callable $reporter): static
    {
        $this->reporter = $reporter ? Closure::fromCallable($reporter) : null;

        return $this;
    }

    protected function report(string $message, string $level = 'info'): void
    {
        if ($this->reporter) {
            ($this->reporter)($message, $level);
        }
    }
}
