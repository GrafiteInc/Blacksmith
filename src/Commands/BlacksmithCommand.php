<?php

namespace Grafite\Blacksmith\Commands;

use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Plan;
use Illuminate\Console\Command;
use Laravel\Forge\Resources\ServerTypes;
use RuntimeException;

abstract class BlacksmithCommand extends Command
{
    protected function blueprints(): BlueprintRepository
    {
        return $this->laravel->make(BlueprintRepository::class);
    }

    protected function state(): StateRepository
    {
        return $this->laravel->make(StateRepository::class);
    }

    protected function plan(bool $dryRun = false): Plan
    {
        return new Plan($dryRun, $this->reporter());
    }

    protected function reporter(): callable
    {
        return function (string $message, string $level = 'info') {
            match ($level) {
                'warn' => $this->warn($message),
                'error' => $this->error($message),
                default => $this->line($message),
            };
        };
    }

    /**
     * The named instance, or every instance when no name is given.
     */
    protected function resolveInstances(?string $name): array
    {
        if ($name) {
            $this->state()->instance($name);

            return [$name];
        }

        $instances = array_keys($this->state()->instances());

        if (empty($instances)) {
            throw new RuntimeException('No instances yet, run blacksmith:localize or blacksmith:instance first.');
        }

        return $instances;
    }

    /**
     * The sites to act on for an instance, --site wins, then the sites on the instance.
     */
    protected function resolveSites(string $instance, array $sites = []): array
    {
        if (! empty($sites)) {
            return $sites;
        }

        $sites = $this->state()->sitesFor($instance);

        if (empty($sites) && count($this->blueprints()->sites()) === 1) {
            return $this->blueprints()->sites();
        }

        return $sites;
    }

    /**
     * The built in profiles are created on first use so a fresh project can make servers.
     */
    protected function ensureProfile(string $name): void
    {
        if ($this->blueprints()->hasProfile($name)) {
            return;
        }

        if (! in_array($name, ['app', 'balancer'], true)) {
            throw new RuntimeException("Server profile [{$name}] not found, create {$this->blueprints()->profilePath($name)}.");
        }

        $this->blueprints()->saveProfile($name, BlueprintRepository::defaultProfile($name === 'balancer' ? ServerTypes::LOADBALANCER : ServerTypes::APP));

        $this->line("Created server profile {$this->blueprints()->profilePath($name)}");
    }

    protected function siteOption(): string
    {
        $site = $this->option('site');

        if ($site) {
            $this->blueprints()->site($site);

            return $site;
        }

        $sites = $this->blueprints()->sites();

        if (count($sites) !== 1) {
            throw new RuntimeException('Pass --site, the blueprint has '.(count($sites) ?: 'no').' sites.');
        }

        return $sites[0];
    }

    protected function failWith(RuntimeException $exception): int
    {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }
}
