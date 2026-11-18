<?php

declare(strict_types=1);

namespace Ivanange\Billing\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;
use Ivanange\Billing\Models\Payment;

/**
 * A person settled a refund the provider never answered clearly: $refunded
 * is what they said the provider shows. "Not refunded" makes the payment
 * refundable again, so a wrong answer can lead to a second refund.
 */
final class StuckRefundResolved
{
    use Dispatchable;

    public function __construct(
        public readonly Payment $payment,
        public readonly bool $refunded,
        public readonly ?Authenticatable $by,
    ) {}
}
