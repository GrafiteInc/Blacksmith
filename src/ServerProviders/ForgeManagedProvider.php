<?php

namespace Grafite\Blacksmith\ServerProviders;

use Grafite\Blacksmith\Forge\ForgeGateway;

/**
 * A provider Forge can create servers on itself, through a server credential in the organization.
 *
 * Forge's API v2 doesn't return the provision command for custom servers, so a server
 * Blacksmith creates on its own can't be provisioned without the dashboard. Forge
 * creates, provisions and later deletes these servers at the provider instead.
 */
interface ForgeManagedProvider
{
    /**
     * The provider fields of Forge's create server request: provider, credential_id and the provider's options.
     */
    public function forgePayload(ForgeGateway $forge, array $options = []): array;
}
