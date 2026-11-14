<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Support;

use Illuminate\Support\Facades\Http;
use Ivanange\Billing\Data\CheckoutRequest;
use Ivanange\Billing\Gateways\Providers\PaystackProvider;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Models\Plan;
use Ivanange\Billing\Models\PlanPrice;
use Ivanange\Billing\Services\OnlineCheckout;
use Ivanange\Billing\Tests\Fixtures\Member;

trait OnlineHelpers
{
    use PaystackFakes;

    protected function monthlyPrice(?Plan $plan = null, int $amount = 1500): PlanPrice
    {
        $plan ??= $this->plan();

        return PlanPrice::query()->create([
            'plan_id' => $plan->id, 'label' => '1 month', 'duration_unit' => 'month', 'duration_count' => 1, 'amount' => $amount,
        ]);
    }

    /**
     * Start a Paystack checkout with the HTTP call faked; returns the pending payment.
     */
    protected function startCheckout(?Member $member = null, ?GatewayInstance $gateway = null, ?PlanPrice $price = null): Payment
    {
        Http::fake(['api.paystack.co/transaction/initialize' => $this->paystackInitialized()]);
        $price ??= $this->monthlyPrice();

        app(OnlineCheckout::class)->start(new CheckoutRequest(
            billable: $member ?? $this->member(),
            plan: $price->plan,
            price: $price,
            gateway: $gateway ?? $this->paystack(),
            method: PaystackProvider::MOBILE_MONEY,
            email: 'ama@example.test',
        ));

        return Payment::query()->latest('id')->firstOrFail();
    }
}
