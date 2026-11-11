<?php

declare(strict_types=1);

namespace Ivanange\Billing\Contracts;

use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;

interface Refunds
{
    /**
     * @param  int|null  $amount  minor units; null = the whole payment
     */
    public function refund(GatewayInstance $instance, Payment $payment, ?int $amount = null): ChargeResult;
}
