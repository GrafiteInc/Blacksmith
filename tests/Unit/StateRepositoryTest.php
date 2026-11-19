<?php

namespace Tests\Unit;

use Tests\TestCase;

class StateRepositoryTest extends TestCase
{
    public function test_roles_follow_the_pool()
    {
        $state = $this->state();

        foreach (['prod' => 1, 'example-com-node-1' => 2, 'lb' => 3, 'staging' => 4] as $name => $id) {
            $state->putInstance($name, ['server_id' => $id, 'profile' => 'app']);
            $state->setSiteId($name, 'example.com', $id * 10);
        }

        $this->assertSame('primary', $state->roleFor('prod', 'example.com'));
        $this->assertSame('prod', $state->primaryInstanceFor('example.com'));

        $state->putPool('example.com', ['balancer' => 'lb', 'primary' => 'prod', 'nodes' => ['prod', 'example-com-node-1']]);

        $this->assertSame('primary', $state->roleFor('prod', 'example.com'));
        $this->assertSame('web', $state->roleFor('example-com-node-1', 'example.com'));
        $this->assertSame('balancer', $state->roleFor('lb', 'example.com'));
        $this->assertSame('primary', $state->roleFor('staging', 'example.com'));
        $this->assertSame('round_robin', $state->pool('example.com')['method']);
    }

    public function test_a_replacement_is_promoted_in_one_swap()
    {
        $state = $this->state();

        $state->putInstance('prod', ['server_id' => 1, 'profile' => 'app']);
        $state->setSiteId('prod', 'example.com', 10);
        $state->putInstance('prod-next', ['server_id' => 2, 'profile' => 'app', 'replaces' => 'prod']);
        $state->setSiteId('prod-next', 'example.com', 20);

        $this->assertSame('prod-next', $state->replacementFor('prod'));
        $this->assertSame('prod', $state->blueprintName('prod-next'));
        $this->assertSame('web', $state->roleFor('prod-next', 'example.com'));
        $this->assertTrue($state->isTransient('prod-next'));

        $state->promoteReplacement('prod', 'prod-old');
        $state->reload();

        $this->assertSame(['prod', 'prod-old'], array_keys($state->instances()));
        $this->assertSame(20, $state->siteId('prod', 'example.com'));
        $this->assertSame(10, $state->siteId('prod-old', 'example.com'));
        $this->assertNull($state->replacementFor('prod'));
        $this->assertSame('prod-old', $state->retiredFor('prod'));
        $this->assertSame('primary', $state->roleFor('prod', 'example.com'));
        $this->assertSame('web', $state->roleFor('prod-old', 'example.com'));
        $this->assertSame('prod', $state->primaryInstanceFor('example.com'));
        $this->assertFalse($state->isTransient('prod'));
    }

    public function test_state_is_persisted_and_names_are_unique()
    {
        $this->state()->putInstance('example-com-node-1', ['server_id' => 5]);

        $this->assertSame('example-com-node-2', $this->state()->nextNodeName('example.com'));

        $this->state()->reload();

        $this->assertSame('example-com-node-1', $this->state()->instanceForServer(5));
        $this->assertNull($this->state()->instanceForServer(6));
        $this->assertFileExists($this->root.'/state.json');
    }
}
