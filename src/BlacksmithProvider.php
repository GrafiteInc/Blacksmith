<?php

namespace Grafite\Blacksmith;

use Grafite\Blacksmith\Blueprint\BlueprintRepository;
use Grafite\Blacksmith\Commands\Backup;
use Grafite\Blacksmith\Commands\Balancer;
use Grafite\Blacksmith\Commands\Dns;
use Grafite\Blacksmith\Commands\Instance;
use Grafite\Blacksmith\Commands\Localize;
use Grafite\Blacksmith\Commands\MakeServer;
use Grafite\Blacksmith\Commands\Replace;
use Grafite\Blacksmith\Commands\Scale;
use Grafite\Blacksmith\Commands\SudoPassword;
use Grafite\Blacksmith\Commands\UpdateServer;
use Grafite\Blacksmith\Commands\Workers;
use Grafite\Blacksmith\Dns\CloudflareDnsProvider;
use Grafite\Blacksmith\Dns\DnsProvider;
use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\ServerProviders\DigitalOceanProvider;
use Grafite\Blacksmith\ServerProviders\ServerProvider;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\AccessReport;
use Grafite\Blacksmith\Support\Defaults;
use Grafite\Blacksmith\Support\RemoteShell;
use Grafite\Blacksmith\Support\SshConfig;
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

        // mergeConfigFrom is shallow, so a published config still gets defaults added in later versions.
        $package = require __DIR__.'/../config/blacksmith.php';

        config(['blacksmith.defaults' => array_replace_recursive($package['defaults'], (array) config('blacksmith.defaults', []))]);

        $this->app->singleton(Forge::class, function () {
            if (empty(config('blacksmith.forge_token'))) {
                throw new RuntimeException('Set BLACKSMITH_FORGE_TOKEN to your Forge API token.');
            }

            return (new Forge(config('blacksmith.forge_token')))->setTimeout(300);
        });

        $this->app->singleton(ForgeGateway::class, fn ($app) => new ForgeGateway(
            $app->make(Forge::class),
            config('blacksmith.forge_organization'),
        ));

        $this->app->singleton(BlueprintRepository::class, fn () => new BlueprintRepository(config('blacksmith.path')));

        $this->app->singleton(StateRepository::class, fn () => new StateRepository(config('blacksmith.path').'/state.json'));

        $this->app->bind(RemoteShell::class, fn () => new RemoteShell(
            config('blacksmith.ssh.user', 'root'),
            config('blacksmith.ssh.key'),
            (int) config('blacksmith.poll_interval', 15),
            (int) config('blacksmith.ssh.port', 22),
        ));

        $this->app->singleton(AccessReport::class);

        $this->app->bind(SshConfig::class, fn () => new SshConfig(
            config('blacksmith.ssh_config.path'),
            filter_var(config('blacksmith.ssh_config.enabled', true), FILTER_VALIDATE_BOOL),
            config('blacksmith.project'),
            Defaults::user(),
            (int) config('blacksmith.ssh.port', 22),
            config('blacksmith.ssh.key') ?: null,
        ));

        $this->app->bind(ServerProvider::class, fn () => match (config('blacksmith.server_provider')) {
            'digitalocean' => new DigitalOceanProvider(
                config('blacksmith.server_provider_token'),
                config('blacksmith.server_provider_options.digitalocean', []),
                (int) config('blacksmith.poll_interval', 15),
            ),
            default => throw new InvalidArgumentException('Unsupported server provider ['.config('blacksmith.server_provider').'], pass --ip to use an existing server.'),
        });

        $this->app->singleton(DnsProvider::class, fn ($app) => match (config('blacksmith.dns.provider')) {
            'cloudflare' => new CloudflareDnsProvider(
                config('blacksmith.dns.cloudflare.token'),
                filter_var(config('blacksmith.dns.cloudflare.proxied', true), FILTER_VALIDATE_BOOL),
                array_replace($app->make(StateRepository::class)->dnsZones(), (array) config('blacksmith.dns.cloudflare.zones', [])),
            ),
            default => throw new InvalidArgumentException('Unsupported DNS provider ['.config('blacksmith.dns.provider').'], set BLACKSMITH_DNS_PROVIDER=cloudflare.'),
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
            Replace::class,
            Dns::class,
            SudoPassword::class,
        ]);
    }
}
