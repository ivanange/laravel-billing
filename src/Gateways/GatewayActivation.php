<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Ivanange\Billing\Events\GatewayActivated;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Services\Concerns\DispatchesAfterCommit;

/**
 * Switches which instance of a provider is offered for new payments. This
 * is an explicit action, not a save hook, and never touches existing
 * payments or subscriptions (they keep their own instance id).
 */
class GatewayActivation
{
    use DispatchesAfterCommit;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Config $config,
    ) {}

    public function activate(GatewayInstance $instance): void
    {
        if ($instance->trashed()) {
            throw BillingException::because('instance_deleted');
        }

        $this->db->transaction(function () use ($instance): void {
            if ($this->config->get('billing.gateways.one_active_per_provider', true)) {
                GatewayInstance::query()
                    ->where('provider', $instance->provider)
                    ->whereKeyNot($instance->getKey())
                    ->where('is_active', true)
                    ->update(['is_active' => false]);
            }

            $instance->forceFill(['is_active' => true])->save();
        });

        $this->afterCommit(fn () => event(new GatewayActivated($instance)));
    }

    public function deactivate(GatewayInstance $instance): void
    {
        $instance->forceFill(['is_active' => false])->save();
    }
}
