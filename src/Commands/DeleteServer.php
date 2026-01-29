<?php

namespace Grafite\Blacksmith\Commands;

use Illuminate\Console\Command;
use Laravel\Forge\Forge;

class DeleteServer extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:delete-server {id}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete a Forge server and remove its local blacksmith configuration.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $forge = new Forge(config('blacksmith.forge_token'));

        $id = $this->argument('id');
        if ($forge->setTimeout(120)->deleteServer($id)) {
            rmdir(base_path('.blacksmith/'.$id));
            $this->info('Server deleted.');
        }

        return 0;
    }
}
