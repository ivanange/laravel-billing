<?php

declare(strict_types=1);

namespace Ivanange\Billing\Contracts;

use Illuminate\Http\Request;
use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Exceptions\InvalidWebhook;
use Ivanange\Billing\Models\GatewayInstance;

interface ReceivesWebhooks
{
    /**
     * Check the request really comes from the provider for THIS instance
     * (its own secret) and turn it into a result.
     *
     * @return ChargeResult|null null for events that don't concern a charge
     *
     * @throws InvalidWebhook when the signature (or source) can't be trusted
     */
    public function parseWebhook(GatewayInstance $instance, Request $request): ?ChargeResult;
}
