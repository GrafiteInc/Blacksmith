<?php

namespace Tests\Unit;

use Grafite\Blacksmith\Actions\ResetSudoPassword;
use Laravel\Forge\Resources\Recipe;
use Laravel\Forge\Resources\RecipeRun;
use RuntimeException;
use Tests\TestCase;

class ResetSudoPasswordTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->state()->putInstance('web-1', ['server_id' => 700, 'ip_address' => '203.0.113.1', 'profile' => 'app']);
    }

    public function test_a_throwaway_root_recipe_sets_the_password_and_is_deleted()
    {
        $script = null;

        $forge = $this->forge();
        $forge->shouldReceive('createRecipe')->once()->ordered()
            ->withArgs(function ($name, $user, $body) use (&$script) {
                $script = $body;

                return str_starts_with($name, 'Blacksmith sudo password web-1 ') && $user === 'root';
            })
            ->andReturn(new Recipe(['id' => 55]));
        $forge->shouldReceive('runRecipe')->once()->ordered()->with(55, 700);
        $forge->shouldReceive('recipeRuns')->with(55)->andReturn(
            [new RecipeRun(['id' => 1, 'server_id' => 700, 'status' => 'running'])],
            [new RecipeRun(['id' => 1, 'server_id' => 700, 'status' => 'finished', 'finished_at' => '2026-11-19 10:00:00'])],
        );
        $forge->shouldReceive('deleteRecipe')->once()->ordered()->with(55);

        $password = $this->app->make(ResetSudoPassword::class)->handle('web-1');

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $password);
        $this->assertSame("printf '%s\\n' 'forge:{$password}' | chpasswd", $script);
    }

    public function test_the_recipe_is_deleted_when_the_run_fails()
    {
        $forge = $this->forge();
        $forge->shouldReceive('createRecipe')->once()->andReturn(new Recipe(['id' => 55]));
        $forge->shouldReceive('runRecipe')->once();
        $forge->shouldReceive('recipeRuns')->andReturn([new RecipeRun(['id' => 1, 'server_id' => 700, 'status' => 'failed', 'output' => 'chpasswd: user not found'])]);
        $forge->shouldReceive('deleteRecipe')->once()->with(55);

        try {
            $this->app->make(ResetSudoPassword::class)->handle('web-1');
            $this->fail('A failed run should throw.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ended as failed', $exception->getMessage());
            $this->assertStringContainsString('chpasswd: user not found', $exception->getMessage());
        }
    }

    public function test_the_recipe_is_deleted_when_the_run_never_finishes()
    {
        config(['blacksmith.provision_timeout' => 3]);

        $forge = $this->forge();
        $forge->shouldReceive('createRecipe')->once()->andReturn(new Recipe(['id' => 55]));
        $forge->shouldReceive('runRecipe')->once();
        $forge->shouldReceive('recipeRuns')->andReturn([]);
        $forge->shouldReceive('deleteRecipe')->once()->with(55);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not finish within 3 seconds');

        $this->app->make(ResetSudoPassword::class)->handle('web-1');
    }

    public function test_the_recipe_is_deleted_when_forge_rejects_the_run()
    {
        $forge = $this->forge();
        $forge->shouldReceive('createRecipe')->once()->andReturn(new Recipe(['id' => 55]));
        $forge->shouldReceive('runRecipe')->once()->andThrow(new RuntimeException('Forge rejected running recipe 55'));
        $forge->shouldReceive('deleteRecipe')->once()->with(55);

        $this->expectExceptionMessage('Forge rejected running recipe 55');

        $this->app->make(ResetSudoPassword::class)->handle('web-1');
    }

    public function test_an_instance_without_a_server_is_refused()
    {
        $this->state()->putInstance('half', ['server_id' => null, 'profile' => 'app']);
        $this->forge()->shouldNotReceive('createRecipe');

        $this->expectExceptionMessage('does not have a Forge server yet');

        $this->app->make(ResetSudoPassword::class)->handle('half');
    }

    public function test_the_script_quotes_the_password()
    {
        $this->assertSame("printf '%s\\n' 'forge:a'\\''b' | chpasswd", ResetSudoPassword::script('forge', "a'b"));
        $this->assertNotSame(ResetSudoPassword::generate(), ResetSudoPassword::generate());
    }
}
