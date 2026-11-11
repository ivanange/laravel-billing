<?php

declare(strict_types=1);

namespace Ivanange\Billing\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Log;
use Ivanange\Billing\Contracts\ChargesOnce;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\OnlinePaymentSettler;

/**
 * Where the provider sends the payer back. Never trusts the query string:
 * it asks the provider about the stored reference, using the payment's
 * own gateway instance, then shows the outcome.
 */
class PaymentReturnController
{
    public function __invoke(string $reference, OnlinePaymentSettler $settler): View
    {
        $payment = Payment::query()->where('reference', $reference)->firstOrFail();
        abort_unless($payment->isOnline(), 404);

        $checked = true;

        if (in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Failed], true)) {
            $instance = $payment->gatewayInstance;
            $provider = $instance->gateway();

            if ($provider instanceof ChargesOnce) {
                try {
                    $settler->settle($payment, $provider->verify($instance, $reference));
                } catch (GatewayError $e) {
                    $checked = false;
                    Log::warning('billing: could not verify returning payment', ['payment_id' => $payment->id, 'provider_message' => $e->providerMessage]);
                }
            }
        }

        return view('billing::pay.result', ['payment' => $payment->refresh(), 'checked' => $checked]);
    }
}
