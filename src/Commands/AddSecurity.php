<?php

namespace Grafite\Blacksmith\Commands;

use Laravel\Forge\Forge;
use Illuminate\Support\Str;
use Illuminate\Console\Command;

class AddSecurity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:add-security';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Config for a security rule on a site.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('"security": [' . PHP_EOL . '    {' . PHP_EOL . '        "name" : "",' . PHP_EOL . '        "path" : "",' . PHP_EOL . '        "credentials": [ {' . PHP_EOL . '            "username" : "",' . PHP_EOL . '            "password" : ""' . PHP_EOL . '        } ]' . PHP_EOL . '    }' . PHP_EOL . '],');

        return 0;
    }
}
