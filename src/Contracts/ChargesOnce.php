<?php

declare(strict_types=1);

namespace Ivanange\Billing\Contracts;

use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Data\PaymentIntent;
use Ivanange\Billing\Data\StartedCharge;
use Ivanange\Billing\Models\GatewayInstance;

/**
 * Hosted one-off checkout: start() returns where to send the payer,
 * verify() asks the provider what happened to a reference.
 *
 * The instance is always passed in: it is the payment's OWN instance
 * (payments.gateway_instance_id), never "the active one of this provider".
 */
interface ChargesOnce
{
    public function start(GatewayInstance $instance, PaymentIntent $intent): StartedCharge;

    public function verify(GatewayInstance $instance, string $reference): ChargeResult;
}
