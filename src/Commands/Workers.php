<?php

namespace Grafite\Blacksmith\Commands;

use Grafite\Blacksmith\Actions\SyncWorkers;
use RuntimeException;

class Workers extends BlacksmithCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:workers
        {action : up, down, restart or status}
        {instance? : The instance, all instances when omitted}
        {--site=* : Only these sites}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Bring site queue workers up (from the blueprint), down, restart them or show their status.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(SyncWorkers $workers)
    {
        $action = $this->argument('action');

        if (! in_array($action, ['up', 'down', 'restart', 'status'], true)) {
            $this->error('The action must be one of: up, down, restart, status.');

            return self::FAILURE;
        }

        $plan = $this->plan();

        try {
            foreach ($this->resolveInstances($this->argument('instance')) as $instance) {
                $serverId = (int) $this->state()->instance($instance)['server_id'];

                foreach ($this->resolveSites($instance, $this->option('site')) as $domain) {
                    $siteId = $this->state()->siteId($instance, $domain);
                    $role = $this->state()->roleFor($instance, $domain);
                    $label = "{$instance} {$domain}: ";

                    if (! $siteId || $role === 'balancer') {
                        continue;
                    }

                    match ($action) {
                        'up' => $role === 'primary'
                            ? $workers->sync($serverId, $siteId, $this->blueprints()->site($domain)['workers'], $plan, $label)
                            : $this->line($label.'Skipped, workers only run on the primary node.'),
                        'down' => $workers->down($serverId, $siteId, $plan, $label),
                        'restart' => $workers->restart($serverId, $siteId, $plan, $label),
                        'status' => $this->status($label, $workers->status($serverId, $siteId)),
                    };
                }
            }
        } catch (RuntimeException $exception) {
            return $this->failWith($exception);
        }

        if ($action !== 'status') {
            $this->info(empty($plan->steps()) ? 'Workers already match.' : 'Workers '.$action.'.');
        }

        return self::SUCCESS;
    }

    protected function status(string $label, array $rows): void
    {
        $this->info(rtrim($label, ': '));

        empty($rows)
            ? $this->line('  No workers.')
            : $this->table(['Connection', 'Queue', 'Processes', 'Timeout', 'Tries', 'PHP', 'Status'], $rows);
    }
}
