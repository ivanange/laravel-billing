<?php

declare(strict_types=1);

namespace Ivanange\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Ivanange\Billing\Models\Payment;

final class PaymentConfirmed
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment) {}
}
