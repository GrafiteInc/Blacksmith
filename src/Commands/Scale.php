<?php

namespace Grafite\Blacksmith\Commands;

use Grafite\Blacksmith\Actions\BuildInstance;
use Grafite\Blacksmith\Actions\ConfigureBalancer;
use Grafite\Blacksmith\ServerProviders\ServerProvider;
use Grafite\Blacksmith\Support\EnvFile;
use Grafite\Blacksmith\Support\Plan;
use Laravel\Forge\Forge;
use RuntimeException;

class Scale extends BlacksmithCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:scale
        {site : The site to scale}
        {--to= : The total number of nodes behind the load balancer}
        {--remove=* : Remove these nodes}
        {--profile= : The server profile for new nodes, defaults to the primary node\'s profile}
        {--force : Delete removed servers without asking}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add or remove web nodes behind a site\'s load balancer.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(Forge $forge, BuildInstance $build, ConfigureBalancer $balancer)
    {
        $domain = $this->argument('site');
        $plan = $this->plan();

        try {
            $this->blueprints()->site($domain);

            if (empty($this->state()->pool($domain)['balancer'])) {
                throw new RuntimeException("{$domain} does not have a load balancer, run: php artisan blacksmith:balancer {name} --site={$domain}");
            }

            $pool = $this->state()->pool($domain);

            foreach (EnvFile::scaleWarnings($this->blueprints()->env($domain, $pool['primary'])) as $warning) {
                $this->warn($warning);
            }

            if ($remove = $this->option('remove')) {
                return $this->removeNodes($forge, $balancer, $plan, $domain, $remove);
            }

            if (is_null($this->option('to'))) {
                $this->line("{$domain} is balanced across: ".implode(', ', $pool['nodes']));

                return self::SUCCESS;
            }

            $target = (int) $this->option('to');
            $current = count($pool['nodes']);

            if ($target < 1) {
                throw new RuntimeException('A site needs at least one node.');
            }

            if ($target < $current) {
                $removable = array_values(array_diff($pool['nodes'], [$pool['primary']]));

                return $this->removeNodes($forge, $balancer, $plan, $domain, array_slice($removable, -($current - $target)));
            }

            if ($target === $current) {
                $this->info("{$domain} already has {$current} node(s).");

                return self::SUCCESS;
            }

            $profile = $this->option('profile') ?? $this->state()->instance($pool['primary'])['profile'];
            $build->reportUsing($this->reporter());

            for ($i = $current; $i < $target; $i++) {
                $node = $this->state()->nextNodeName($domain);

                $build->handle($node, $profile, [$domain], $plan, null, null, 'web');

                $this->state()->putPool($domain, [
                    'nodes' => array_merge($this->state()->pool($domain)['nodes'], [$node]),
                ]);
            }

            $balancer->handle($domain, $plan);
        } catch (RuntimeException $exception) {
            return $this->failWith($exception);
        }

        $this->info("{$domain} is balanced across: ".implode(', ', $this->state()->pool($domain)['nodes']));

        return self::SUCCESS;
    }

    protected function removeNodes(Forge $forge, ConfigureBalancer $balancer, Plan $plan, string $domain, array $nodes): int
    {
        $pool = $this->state()->pool($domain);

        foreach ($nodes as $node) {
            if (! in_array($node, $pool['nodes'], true)) {
                throw new RuntimeException("{$node} is not a node of {$domain}.");
            }

            if ($node === $pool['primary']) {
                throw new RuntimeException("{$node} is the primary node of {$domain} and runs its scheduler and workers, it can't be removed.");
            }
        }

        $balancer->handle($domain, $plan, $nodes);

        if (! $this->option('force') && ! $this->confirm('Delete the server(s) for '.implode(', ', $nodes).'?')) {
            $this->warn('The node(s) are marked down on the balancer but were kept.');

            return self::SUCCESS;
        }

        $this->state()->putPool($domain, [
            'nodes' => array_values(array_diff($pool['nodes'], $nodes)),
        ]);

        $balancer->handle($domain, $plan);

        foreach ($nodes as $node) {
            $instance = $this->state()->instance($node);

            $plan->step("{$node}: Delete Forge server", fn () => $forge->deleteServer($instance['server_id']));

            if (! empty($instance['provider_id'])) {
                $plan->step("{$node}: Destroy {$instance['provider']} server", fn () => $this->laravel->make(ServerProvider::class)->destroy($instance['provider_id']));
            }

            $this->state()->forgetInstance($node);
        }

        $this->info("{$domain} is balanced across: ".implode(', ', $this->state()->pool($domain)['nodes']));

        return self::SUCCESS;
    }
}
