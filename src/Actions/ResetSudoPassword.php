<?php

namespace Grafite\Blacksmith\Actions;

use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\State\StateRepository;
use Grafite\Blacksmith\Support\Defaults;
use Grafite\Blacksmith\Support\Poll;
use Illuminate\Support\Str;
use Laravel\Forge\Resources\RecipeRun;
use RuntimeException;

/**
 * Give the Forge user a new sudo password, Forge's API v2 no longer returns the one it set.
 *
 * A throwaway recipe runs chpasswd as root and is deleted straight after, so the
 * password isn't left in Forge. It's returned to the caller and stored nowhere.
 */
class ResetSudoPassword
{
    /**
     * Recipe run statuses that mean chpasswd didn't run.
     */
    public const FAILED = ['failed', 'failure', 'error', 'errored', 'timeout', 'timed-out', 'cancelled', 'canceled'];

    /**
     * Recipe run statuses that mean it's done, finished_at being set means the same.
     */
    public const FINISHED = ['finished', 'success', 'successful', 'completed', 'complete', 'done'];

    public function __construct(protected ForgeGateway $forge, protected StateRepository $state) {}

    public function handle(string $instance): string
    {
        $serverId = (int) ($this->state->instance($instance)['server_id'] ?? 0);

        if (! $serverId) {
            throw new RuntimeException("{$instance} does not have a Forge server yet.");
        }

        $user = Defaults::user();
        $password = static::generate();

        $recipe = $this->forge->createRecipe('Blacksmith sudo password '.$instance.' '.Str::lower(Str::random(6)), 'root', static::script($user, $password));

        if (! $recipe->id) {
            throw new RuntimeException("{$instance}: Forge did not return the sudo password recipe, check for it under Recipes in Forge and delete it.");
        }

        try {
            $this->forge->runRecipe($recipe->id, $serverId);

            $this->waitForRun($instance, $recipe->id, $serverId);
        } finally {
            $this->forge->deleteRecipe($recipe->id);
        }

        return $password;
    }

    /**
     * Letters and numbers only, so neither chpasswd's user:password format nor the shell can misread it.
     */
    public static function generate(): string
    {
        return Str::password(32, letters: true, numbers: true, symbols: false, spaces: false);
    }

    public static function script(string $user, string $password): string
    {
        return 'printf \'%s\n\' '.escapeshellarg("{$user}:{$password}").' | chpasswd';
    }

    protected function waitForRun(string $instance, int $recipeId, int $serverId): void
    {
        $timeout = (int) config('blacksmith.provision_timeout', 1800);

        /** @var RecipeRun|null $run */
        $run = Poll::until(function () use ($recipeId, $serverId) {
            $run = collect($this->forge->recipeRuns($recipeId))
                ->filter(fn (RecipeRun $run) => ! $run->serverId || $run->serverId === $serverId)
                ->sortByDesc('id')
                ->first();

            return $run && static::ended($run) ? $run : null;
        }, $timeout, (int) config('blacksmith.poll_interval', 15));

        if (! $run) {
            throw new RuntimeException("{$instance}: The sudo password recipe did not finish within {$timeout} seconds, the password may not have changed.");
        }

        if (in_array(Str::lower((string) $run->status), static::FAILED, true)) {
            throw new RuntimeException("{$instance}: The sudo password recipe ended as {$run->status}, the password did not change."
                .(trim((string) $run->output) !== '' ? PHP_EOL.Str::limit(trim($run->output), 2000) : ''));
        }
    }

    protected static function ended(RecipeRun $run): bool
    {
        $status = Str::lower((string) $run->status);

        return ! empty($run->finishedAt) || in_array($status, static::FINISHED, true) || in_array($status, static::FAILED, true);
    }
}
