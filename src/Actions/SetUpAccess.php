<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\AccessReport;
use Grafite\Blacksmith\Support\Defaults;
use Grafite\Blacksmith\Support\ReportsProgress;
use Grafite\Blacksmith\Support\SshConfig;
use RuntimeException;

/**
 * Everything needed to log in to an instance: a known sudo password, an SSH config entry
 * and a line in the access report printed at the end of the command.
 *
 * Neither failing stops a build, the server works without them and both can be redone.
 */
class SetUpAccess
{
    use ReportsProgress;

    public function __construct(
        protected StateRepository $state,
        protected ResetSudoPassword $sudo,
        protected SshConfig $ssh,
        protected AccessReport $access,
    ) {}

    /**
     * @param  bool|null  $resetPassword  null follows blacksmith.sudo_password
     */
    public function handle(string $instance, ?bool $resetPassword = null): ?string
    {
        $record = $this->state->instance($instance);
        $ip = $record['ip_address'] ?? null;
        $password = null;

        if ($resetPassword ?? filter_var(config('blacksmith.sudo_password', true), FILTER_VALIDATE_BOOL)) {
            $this->report("{$instance}: Setting a new sudo password for ".Defaults::user());

            try {
                $password = $this->sudo->handle($instance);
            } catch (RuntimeException $exception) {
                $this->report($exception->getMessage().PHP_EOL."Set one later with: php artisan blacksmith:sudo-password {$instance}", 'warn');
            }
        }

        if ($ip) {
            $this->writeSshConfig($instance, $ip);

            // A replacement takes the replaced instance's name at cutover, so that's the name worth saving.
            $replaces = $record['replaces'] ?? null;
            $ssh = $this->ssh->has($instance) ? 'ssh '.$this->ssh->alias($instance) : null;

            if ($ssh && $replaces) {
                $ssh .= ' (ssh '.$this->ssh->alias($replaces).' after cutover)';
            }

            $this->access->add($this->ssh->alias($replaces ?? $instance), $ip, Defaults::user(), $password, $ssh);
        }

        return $password;
    }

    protected function writeSshConfig(string $instance, string $ip): void
    {
        try {
            $this->ssh->put($instance, $ip);
        } catch (RuntimeException $exception) {
            $this->report("{$instance}: The SSH config was not updated: {$exception->getMessage()}", 'warn');
        }
    }
}
