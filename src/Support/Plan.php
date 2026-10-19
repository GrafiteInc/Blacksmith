<?php

namespace Grafite\Blacksmith\Support;

use Closure;

/**
 * Records each change an action makes, and in dry-run mode records it without making it.
 */
class Plan
{
    protected array $steps = [];

    protected ?Closure $reporter;

    public function __construct(public readonly bool $dryRun = false, ?callable $reporter = null)
    {
        $this->reporter = $reporter ? Closure::fromCallable($reporter) : null;
    }

    public function step(string $description, Closure $action): mixed
    {
        $this->steps[] = $description;

        if ($this->dryRun) {
            $this->report('[plan] '.$description);

            return null;
        }

        $result = $action();

        $this->report($description);

        return $result;
    }

    public function note(string $message, string $level = 'warn'): void
    {
        $this->report($message, $level);
    }

    public function steps(): array
    {
        return $this->steps;
    }

    protected function report(string $message, string $level = 'info'): void
    {
        if ($this->reporter) {
            ($this->reporter)($message, $level);
        }
    }
}
