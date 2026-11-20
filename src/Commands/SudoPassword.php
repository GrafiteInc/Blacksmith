<?php

namespace Grafite\Blacksmith\Commands;

use Grafite\Blacksmith\Actions\SetUpAccess;
use RuntimeException;

class SudoPassword extends BlacksmithCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'blacksmith:sudo-password
        {instance : The instance whose Forge user gets a new sudo password}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Give the Forge user on an instance a new sudo password, and refresh its SSH config entry.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(SetUpAccess $access)
    {
        $instance = $this->argument('instance');

        try {
            $this->state()->instance($instance);

            $password = $access->reportUsing($this->reporter())->handle($instance, true);
        } catch (RuntimeException $exception) {
            return $this->failWith($exception);
        } finally {
            $this->printAccessReport();
        }

        return $password ? self::SUCCESS : self::FAILURE;
    }
}
