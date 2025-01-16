<?php

namespace Grafite\Blacksmith\Commands;

use Laravel\Forge\Forge;
use Illuminate\Support\Str;
use Illuminate\Console\Command;

class WorkersList extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:workers-list {--server=} {--site=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reset the workers for a Forge site.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $forge = new Forge(config('blacksmith.forge_token'));
        $serverIds = [$this->option('server')];

        if (! $this->option('server')) {
            $serverIds = collect(glob(base_path('.blacksmith') . '/*' , GLOB_ONLYDIR))->map(function ($server) {
                return Str::of($server)->replace(base_path('.blacksmith/'), '')->toString();
            })->toArray();
        }

        foreach ($serverIds as $serverId) {
            $basePath = base_path('.blacksmith/'.$serverId.'/');
            $config = json_decode(file_get_contents($basePath.'config.json'), true);
            $siteConfigs = $config['sites'];

            if ($this->option('site')) {
                $config = collect($config['sites'])->where('id', $this->option('site'))->first();
                $siteConfigs = ['sites' => $config];
            }

            foreach ($siteConfigs as $config) {
                $siteId = $config['id'];
                $workers = collect();

                foreach ($forge->workers($serverId, $siteId) as $worker) {
                    $workers->push(collect($worker->attributes)->filter(function ($value, $key) {
                        return in_array($key, [
                            'connection',
                            'queue',
                            'timeout',
                            'tries',
                            'stopwaitsecs',
                            'status',
                            'processes',
                            'displayable_php_version',
                        ]);
                    })->toArray());
                }

                // Handling workers
                if (isset($config['workers'])) {
                    $this->table(
                        [
                            'Connection',
                            'Queue',
                            'Timeout',
                            'Tries',
                            'Stopwaitsecs',
                            'Status',
                            'PHP Version',
                            'Processes',
                        ],
                        $workers->toArray()
                    );
                }
            }
        }

        return 0;
    }
}
