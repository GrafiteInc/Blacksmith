<?php

namespace Grafite\Blacksmith\Commands;

use Laravel\Forge\Forge;
use Illuminate\Support\Str;
use Illuminate\Console\Command;

class AddWorker extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:add-worker';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Config for a worker on a site.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('"workers": [' . PHP_EOL . '    {' . PHP_EOL . '        "connection" : "database",' . PHP_EOL . '        "queue" : "default",' . PHP_EOL . '        "tries": 1,' . PHP_EOL . '        "timeout": 0,' . PHP_EOL . '        "processes": 1,' . PHP_EOL . '        "stopwaitsecs": 600,' . PHP_EOL . '        "sleep": 10,' . PHP_EOL . '        "force": false,' . PHP_EOL . '        "daemon": true,' . PHP_EOL . '        "php_version": "php"' . PHP_EOL . '    }' . PHP_EOL . '],');

        return 0;
    }
}
