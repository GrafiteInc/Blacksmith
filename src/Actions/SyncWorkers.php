<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Support\Plan;
use Laravel\Forge\Forge;

class SyncWorkers
{
    public const FIELDS = [
        'connection' => 'database',
        'queue' => 'default',
        'timeout' => 60,
        'sleep' => 10,
        'tries' => null,
        'processes' => 1,
        'stopwaitsecs' => 10,
        'daemon' => false,
        'force' => false,
        'php_version' => 'php',
    ];

    public function __construct(protected Forge $forge) {}

    /**
     * Make the site's workers match the blueprint, workers are only
     * recreated when they differ so this is safe to run repeatedly.
     */
    public function sync(int $serverId, ?int $siteId, array $workers, Plan $plan, string $label = ''): void
    {
        $desired = collect($workers)->map(fn ($worker) => static::normalize($worker));
        $existing = $siteId ? collect($this->forge->workers($serverId, $siteId)) : collect();

        $signature = fn ($workers) => $workers->map(fn ($worker) => json_encode($worker))->sort()->values()->toArray();

        if ($siteId && $signature($desired) === $signature($existing->map(fn ($worker) => static::normalize($worker->attributes)))) {
            return;
        }

        if ($existing->isNotEmpty()) {
            $plan->step("{$label}Remove {$existing->count()} worker(s)", function () use ($existing, $serverId, $siteId) {
                foreach ($existing as $worker) {
                    $this->forge->deleteWorker($serverId, $siteId, $worker->id);
                }
            });
        }

        foreach ($desired as $worker) {
            $plan->step("{$label}Create worker {$worker['connection']}:{$worker['queue']} x{$worker['processes']}", function () use ($serverId, $siteId, $worker) {
                $this->forge->createWorker($serverId, $siteId, $worker, false);
            });
        }
    }

    public function down(int $serverId, int $siteId, Plan $plan, string $label = ''): void
    {
        foreach ($this->forge->workers($serverId, $siteId) as $worker) {
            $plan->step("{$label}Remove worker {$worker->connection}:{$worker->queue}", function () use ($serverId, $siteId, $worker) {
                $this->forge->deleteWorker($serverId, $siteId, $worker->id);
            });
        }
    }

    public function restart(int $serverId, int $siteId, Plan $plan, string $label = ''): void
    {
        foreach ($this->forge->workers($serverId, $siteId) as $worker) {
            $plan->step("{$label}Restart worker {$worker->connection}:{$worker->queue}", function () use ($serverId, $siteId, $worker) {
                $this->forge->restartWorker($serverId, $siteId, $worker->id, false);
            });
        }
    }

    public function status(int $serverId, int $siteId): array
    {
        return collect($this->forge->workers($serverId, $siteId))->map(fn ($worker) => [
            $worker->connection,
            $worker->queue,
            $worker->processes,
            $worker->timeout,
            $worker->tries ?? '-',
            $worker->attributes['php_version'] ?? '-',
            $worker->status ?? '-',
        ])->toArray();
    }

    public static function normalize(array $worker): array
    {
        $worker = array_replace(static::FIELDS, array_intersect_key($worker, static::FIELDS));

        foreach (['timeout', 'sleep', 'processes', 'stopwaitsecs'] as $key) {
            $worker[$key] = (int) $worker[$key];
        }

        $worker['tries'] = is_null($worker['tries']) ? null : (int) $worker['tries'];
        $worker['daemon'] = (bool) $worker['daemon'];
        $worker['force'] = (bool) $worker['force'];

        return $worker;
    }
}
