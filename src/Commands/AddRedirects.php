<?php

namespace Grafite\Blacksmith\Commands;

use Laravel\Forge\Forge;
use Illuminate\Support\Str;
use Illuminate\Console\Command;

class AddRedirects extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:add-redirects';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Config for a redirect rule on a site.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->info('"redirects": [' . PHP_EOL . '    {' . PHP_EOL . '        "from" : "example.com",' . PHP_EOL . '        "to" : "https://example.com",' . PHP_EOL . '        "type": "permanent"' . PHP_EOL . '    }' . PHP_EOL . '],');

        return 0;
    }
}
