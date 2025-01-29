<?php

namespace Grafite\Blacksmith;

use Grafite\Blacksmith\Commands\Setup;
use Grafite\Blacksmith\Commands\Backup;
use Grafite\Blacksmith\Commands\Deploy;
use Grafite\Blacksmith\Commands\SiteUp;
use Illuminate\Support\ServiceProvider;
use Grafite\Blacksmith\Commands\Localize;
use Grafite\Blacksmith\Commands\SiteDown;
use Grafite\Blacksmith\Commands\AddWorker;
use Grafite\Blacksmith\Commands\BuildSite;
use Grafite\Blacksmith\Commands\WorkersUp;
use Grafite\Blacksmith\Commands\DeploySite;
use Grafite\Blacksmith\Commands\UpdateSite;
use Grafite\Blacksmith\Commands\AddSecurity;
use Grafite\Blacksmith\Commands\BuildServer;
use Grafite\Blacksmith\Commands\CloneServer;
use Grafite\Blacksmith\Commands\WorkersDown;
use Grafite\Blacksmith\Commands\WorkersList;
use Grafite\Blacksmith\Commands\AddRedirects;
use Grafite\Blacksmith\Commands\UpdateServer;
use Grafite\Blacksmith\Commands\WorkersReset;
use Grafite\Blacksmith\Commands\SiteWorkersClear;

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
            CloneServer::class,
            BuildSite::class,
            Localize::class,
            UpdateSite::class,
            UpdateServer::class,
            AddWorker::class,
            AddSecurity::class,
            AddRedirects::class,
            WorkersDown::class,
            WorkersUp::class,
            WorkersReset::class,
            WorkersList::class,
            SiteDown::class,
            SiteUp::class,
            Deploy::class,
        ]);
    }
}
