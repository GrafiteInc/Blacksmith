<?php

namespace Tests\Fakes;

use Grafite\Blacksmith\Forge\ForgeGateway;
use Grafite\Blacksmith\ServerProviders\ForgeManagedProvider;

class FakeForgeManagedProvider extends FakeServerProvider implements ForgeManagedProvider
{
    public array $payloadOptions = [];

    public function forgePayload(ForgeGateway $forge, array $options = []): array
    {
        $this->payloadOptions[] = $options;

        return ['provider' => 'ocean2', 'credential_id' => 5, 'ocean2' => array_replace(['region_id' => 'tor1', 'size_id' => 's-1vcpu-1gb'], $options)];
    }
}
