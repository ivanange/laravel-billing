<?php

declare(strict_types=1);

namespace Ivanange\Billing\Data;

use Illuminate\Database\Eloquent\Model;
use Ivanange\Billing\Contracts\Billable;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Plan;
use Ivanange\Billing\Models\PlanPrice;

/**
 * A payer's choice on the pay page: what to buy (billable, plan, price
 * preset) and how to pay (instance, method). The amount always comes from
 * the price preset, never from the request.
 */
final readonly class CheckoutRequest
{
    public function __construct(
        public Model&Billable $billable,
        public Plan $plan,
        public PlanPrice $price,
        public GatewayInstance $gateway,
        public string $method,
        public ?string $email = null,
        public ?string $phone = null,
        public ?Model $payer = null,
    ) {}
}
