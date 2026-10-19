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
