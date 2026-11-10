<?php

declare(strict_types=1);

namespace Ivanange\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Ivanange\Billing\Models\GatewayInstance;

final class GatewayActivated
{
    use Dispatchable;

    public function __construct(public readonly GatewayInstance $instance) {}
}
