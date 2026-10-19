<?php

namespace Grafite\Blacksmith\Commands;

use Grafite\Blacksmith\Actions\PullFromForge;
use Grafite\Blacksmith\Blueprint\LegacyMigrator;
use Illuminate\Support\Str;
use Laravel\Forge\Forge;
use RuntimeException;

class Localize extends BlacksmithCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:localize
        {--server= : The Forge server ID to pull}
        {--instance= : The name to give the instance}
        {--profile= : The server profile to save the server as}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pull Forge server and site configuration into local blueprints.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(LegacyMigrator $migrator, PullFromForge $pull)
    {
        try {
            $this->blueprints()->ensureRoot();
            $this->ensureGitIgnored();

            if ($migrator->detect()) {
                $this->info('Migrating the v1 configuration.');
                $migrator->reportUsing($this->reporter())->migrate();
            }

            $pull->reportUsing($this->reporter());

            if ($this->option('server')) {
                $pull->server($this->option('server'), $this->option('instance'), $this->option('profile'));
            } elseif ($instances = $this->state()->instances()) {
                foreach ($instances as $name => $instance) {
                    if (! empty($instance['server_id'])) {
                        $pull->server($instance['server_id'], $name);
                    }
                }
            } else {
                [$serverId, $instance] = $this->chooseServer();
                $pull->server($serverId, $instance, $this->option('profile'));
            }
        } catch (RuntimeException $exception) {
            return $this->failWith($exception);
        }

        $this->info('Blueprints saved to '.$this->blueprints()->root());

        return self::SUCCESS;
    }

    protected function chooseServer(): array
    {
        if (! $this->input->isInteractive()) {
            throw new RuntimeException('Pass --server=ID to choose the Forge server to pull.');
        }

        $servers = collect($this->laravel->make(Forge::class)->servers())
            ->mapWithKeys(fn ($server) => [$server->id => "{$server->name} ({$server->ipAddress})"]);

        if ($servers->isEmpty()) {
            throw new RuntimeException('No servers found in your Forge account.');
        }

        $label = $this->choice('Which Forge server should be localized?', $servers->values()->toArray());
        $serverId = $servers->search($label);

        $instance = $this->option('instance')
            ?? $this->ask('What should this instance be called?', Str::slug(Str::before($label, ' (')));

        return [$serverId, $instance];
    }

    protected function ensureGitIgnored(): void
    {
        $gitignore = base_path('.gitignore');
        $root = $this->blueprints()->root();
        $relative = '/'.ltrim(Str::after($root, base_path()), '/');

        if (! Str::startsWith($root, base_path()) || ! file_exists($gitignore) || Str::contains(file_get_contents($gitignore), $relative)) {
            return;
        }

        file_put_contents($gitignore, rtrim(file_get_contents($gitignore)).PHP_EOL.$relative.PHP_EOL);
    }
}
