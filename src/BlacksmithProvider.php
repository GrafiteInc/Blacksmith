<?php

namespace Grafite\Blacksmith;

use Grafite\Blacksmith\Commands\Setup;
use Grafite\Blacksmith\Commands\Backup;
use Illuminate\Support\ServiceProvider;
use Grafite\Blacksmith\Commands\Localize;
use Grafite\Blacksmith\Commands\BuildSite;
use Grafite\Blacksmith\Commands\UpdateSite;
use Grafite\Blacksmith\Commands\BuildServer;

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
        $this->commands([
            Setup::class,
            Backup::class,
            BuildServer::class,
            BuildSite::class,
            Localize::class,
            UpdateSite::class,
        ]);
    }
}
