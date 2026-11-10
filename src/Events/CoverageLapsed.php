<?php

declare(strict_types=1);

namespace Ivanange\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Ivanange\Billing\Models\Subscription;

final class CoverageLapsed
{
    use Dispatchable;

    public function __construct(public readonly Subscription $subscription) {}
}
