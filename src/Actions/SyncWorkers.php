<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\Support\Defaults;
use Grafite\Blacksmith\Support\Php;
use Grafite\Blacksmith\Support\Plan;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Forge\Resources\BackgroundProcess;
use Laravel\Forge\Resources\Site;

/**
 * Queue workers, run as Forge background processes in the site's directory.
 *
 * Forge API v2 has no queue worker endpoints, so each worker in the blueprint
 * is a supervisor process running `artisan queue:work`. A site's workers are
 * the server's background processes in its directory running queue:work.
 */
class SyncWorkers
{
    /**
     * The fields a worker has, the values are used when the blueprint leaves one out.
     */
    public const FIELDS = [
        'connection' => 'database',
        'queue' => 'default',
        'timeout' => 60,
        'sleep' => 10,
        'tries' => null,
        'processes' => 1,
        'stopwaitsecs' => 10,
        'force' => false,
        'php_version' => 'php',
    ];

    public function __construct(protected ForgeGateway $forge) {}

    /**
     * Worker fields with the values from blacksmith.defaults.worker.
     */
    public static function fields(): array
    {
        return array_replace(static::FIELDS, array_intersect_key(Defaults::section('worker'), static::FIELDS));
    }

    /**
     * Make the site's workers match the blueprint, workers are only
     * recreated when they differ so this is safe to run repeatedly.
     */
    public function sync(int $serverId, ?Site $site, array $workers, Plan $plan, string $label = ''): void
    {
        $desired = collect($workers)->map(fn ($worker) => static::normalize($worker));
        $existing = $site ? $this->processes($serverId, $site) : collect();

        $signature = fn ($workers) => $workers->map(fn ($worker) => static::toCommand($worker).' x'.$worker['processes'])->sort()->values()->toArray();

        if ($site && $signature($desired) === $signature($existing->map(fn ($process) => static::fromProcess($process)))) {
            return;
        }

        if ($existing->isNotEmpty()) {
            $plan->step("{$label}Remove {$existing->count()} worker(s)", function () use ($existing, $serverId) {
                foreach ($existing as $process) {
                    $this->forge->deleteBackgroundProcess($serverId, $process->id);
                }
            });
        }

        foreach ($desired as $worker) {
            $plan->step("{$label}Create worker {$worker['connection']}:{$worker['queue']} x{$worker['processes']}", function () use ($serverId, $site, $worker) {
                $this->forge->createBackgroundProcess($serverId, static::payload($site, $worker));
            });
        }
    }

    public function down(int $serverId, Site $site, Plan $plan, string $label = ''): void
    {
        foreach ($this->processes($serverId, $site) as $process) {
            $worker = static::fromProcess($process);

            $plan->step("{$label}Remove worker {$worker['connection']}:{$worker['queue']}", function () use ($serverId, $process) {
                $this->forge->deleteBackgroundProcess($serverId, $process->id);
            });
        }
    }

    public function restart(int $serverId, Site $site, Plan $plan, string $label = ''): void
    {
        foreach ($this->processes($serverId, $site) as $process) {
            $worker = static::fromProcess($process);

            $plan->step("{$label}Restart worker {$worker['connection']}:{$worker['queue']}", function () use ($serverId, $process) {
                $this->forge->restartBackgroundProcess($serverId, $process->id);
            });
        }
    }

    public function status(int $serverId, Site $site): array
    {
        return $this->processes($serverId, $site)->map(function ($process) {
            $worker = static::fromProcess($process);

            return [
                $worker['connection'],
                $worker['queue'],
                $worker['processes'],
                $worker['timeout'],
                $worker['tries'] ?? '-',
                $worker['php_version'],
                $process->status ?? '-',
            ];
        })->values()->toArray();
    }

    /**
     * The site's workers, as they are in its blueprint.
     */
    public function pull(int $serverId, Site $site): array
    {
        return $this->processes($serverId, $site)->map(fn ($process) => static::fromProcess($process))->values()->toArray();
    }

    /**
     * @return Collection<int, BackgroundProcess>
     */
    protected function processes(int $serverId, Site $site): Collection
    {
        $root = ForgeGateway::siteRoot($site);

        return collect($this->forge->backgroundProcesses($serverId))
            ->filter(fn ($process) => static::isWorker($process) && rtrim((string) $process->directory, '/') === $root)
            ->values();
    }

    public static function isWorker(BackgroundProcess $process): bool
    {
        return Str::contains((string) $process->command, 'artisan queue:work');
    }

    public static function payload(Site $site, array $worker): array
    {
        $worker = static::normalize($worker);

        return [
            'name' => "{$site->name} {$worker['connection']}:{$worker['queue']}",
            'site_id' => $site->id,
            'command' => static::toCommand($worker),
            'user' => $site->user ?: Defaults::user(),
            'directory' => ForgeGateway::siteRoot($site),
            'processes' => $worker['processes'],
            'stopwaitsecs' => $worker['stopwaitsecs'],
        ];
    }

    public static function toCommand(array $worker): string
    {
        $worker = static::normalize($worker);

        return collect([
            Php::binary($worker['php_version']),
            'artisan queue:work',
            $worker['connection'],
            '--queue='.$worker['queue'],
            '--sleep='.$worker['sleep'],
            '--timeout='.$worker['timeout'],
            is_null($worker['tries']) ? null : '--tries='.$worker['tries'],
            $worker['force'] ? '--force' : null,
        ])->filter()->implode(' ');
    }

    /**
     * Read a worker back out of its queue:work command.
     */
    public static function fromProcess(BackgroundProcess $process): array
    {
        $command = (string) $process->command;

        preg_match('/^(\S+)\s+artisan\s+queue:work(?:\s+([^-\s]\S*))?/', $command, $matches);
        preg_match_all('/--([a-z-]+)(?:=(\S+))?/', $command, $options, PREG_SET_ORDER);

        $options = collect($options)->mapWithKeys(fn ($option) => [$option[1] => $option[2] ?? true]);
        $binary = $matches[1] ?? 'php';

        // queue:work's own defaults apply when an option is missing from the command.
        return static::normalize([
            'connection' => $matches[2] ?? static::fields()['connection'],
            'queue' => $options->get('queue', 'default'),
            'timeout' => $options->get('timeout', 60),
            'sleep' => $options->get('sleep', 3),
            'tries' => $options->get('tries'),
            'processes' => $process->processes ?? 1,
            'stopwaitsecs' => $process->attributes['stopwaitsecs'] ?? static::fields()['stopwaitsecs'],
            'force' => $options->has('force'),
            'php_version' => preg_match('/^php(\d)\.(\d+)$/', $binary, $version) ? "php{$version[1]}{$version[2]}" : 'php',
        ]);
    }

    public static function normalize(array $worker): array
    {
        $worker = array_replace(static::fields(), array_intersect_key($worker, static::FIELDS));

        foreach (['timeout', 'sleep', 'processes', 'stopwaitsecs'] as $key) {
            $worker[$key] = (int) $worker[$key];
        }

        $worker['tries'] = is_null($worker['tries']) ? null : (int) $worker['tries'];
        $worker['force'] = (bool) $worker['force'];

        return $worker;
    }
}
