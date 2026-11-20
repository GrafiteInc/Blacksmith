<?php

namespace Grafite\Blacksmith\Support;

/**
 * The login details of servers made during this run, printed once at the end so they can be saved
 * (to 1Password, for example). They are only ever held in memory.
 */
class AccessReport
{
    /**
     * @var array<string, array{name: string, ip: string, user: string, password: ?string, ssh: ?string}>
     */
    protected array $entries = [];

    public function add(string $name, string $ip, string $user, ?string $password, ?string $ssh = null): void
    {
        $this->entries[$name] = compact('name', 'ip', 'user', 'password', 'ssh');
    }

    public function isEmpty(): bool
    {
        return empty($this->entries);
    }

    public function hasPasswords(): bool
    {
        return collect($this->entries)->contains(fn ($entry) => $entry['password'] !== null);
    }

    public function entries(): array
    {
        return array_values($this->entries);
    }

    public function render(): string
    {
        return collect($this->entries)->map(function ($entry) {
            $lines = [
                'Name:' => $entry['name'],
                'IP Address:' => $entry['ip'],
                'Username:' => $entry['user'],
                'Sudo Password:' => $entry['password'] ?? '(not set, run php artisan blacksmith:sudo-password)',
            ];

            if ($entry['ssh']) {
                $lines['SSH:'] = $entry['ssh'];
            }

            return collect($lines)->map(fn ($value, $label) => str_pad($label, 16).$value)->implode(PHP_EOL);
        })->implode(PHP_EOL.PHP_EOL);
    }

    public function flush(): void
    {
        $this->entries = [];
    }
}
