<?php

namespace Grafite\Blacksmith;

use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\Commands\Backup;
use Grafite\Blacksmith\Commands\Balancer;
use Grafite\Blacksmith\Commands\Instance;
use Grafite\Blacksmith\Commands\Localize;
use Grafite\Blacksmith\Commands\MakeServer;
use Grafite\Blacksmith\Commands\Scale;
use Grafite\Blacksmith\Commands\UpdateServer;
use Grafite\Blacksmith\Commands\Workers;
use Grafite\Blacksmith\ServerProviders\DigitalOceanProvider;
use Grafite\Blacksmith\ServerProviders\ServerProvider;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\RemoteShell;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Forge\Forge;
use RuntimeException;

class BlacksmithProvider extends ServiceProvider
{
    /**
     * Boot method.
     *
     * @return void
     */
    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/blacksmith.php' => base_path('config/blacksmith.php'),
        ]);
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/blacksmith.php', 'blacksmith');

        $this->app->singleton(Forge::class, function () {
            if (empty(config('blacksmith.forge_token'))) {
                throw new RuntimeException('Set BLACKSMITH_FORGE_TOKEN to your Forge API token.');
            }

            return (new Forge(config('blacksmith.forge_token')))->setTimeout(300);
        });

        $this->app->singleton(BlueprintRepository::class, fn () => new BlueprintRepository(config('blacksmith.path')));

        $this->app->singleton(StateRepository::class, fn () => new StateRepository(config('blacksmith.path').'/state.json'));

        $this->app->bind(RemoteShell::class, fn () => new RemoteShell(
            config('blacksmith.ssh.user', 'root'),
            config('blacksmith.ssh.key'),
            (int) config('blacksmith.poll_interval', 15),
        ));

        $this->app->bind(ServerProvider::class, fn () => match (config('blacksmith.server_provider')) {
            'digitalocean' => new DigitalOceanProvider(
                config('blacksmith.server_provider_token'),
                config('blacksmith.server_provider_options.digitalocean', []),
                (int) config('blacksmith.poll_interval', 15),
            ),
            default => throw new InvalidArgumentException('Unsupported server provider ['.config('blacksmith.server_provider').'], pass --ip to use an existing server.'),
        });

        $this->commands([
            Localize::class,
            MakeServer::class,
            UpdateServer::class,
            Workers::class,
            Backup::class,
            Instance::class,
            Balancer::class,
            Scale::class,
        ]);
    }
}
