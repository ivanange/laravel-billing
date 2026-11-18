<?php

declare(strict_types=1);

namespace Ivanange\Billing\Http\Controllers;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Ivanange\Billing\Contracts\ChargesOnce;
use Ivanange\Billing\Enums\FailureReason;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\OnlinePaymentSettler;
use Throwable;

/**
 * Where the provider sends the payer back. Never trusts the query string:
 * it asks the provider about the stored reference, using the payment's
 * own gateway instance, then shows the outcome.
 */
class PaymentReturnController
{
    public function __construct(private readonly ViewFactory $views) {}

    public function __invoke(string $reference, OnlinePaymentSettler $settler): Response
    {
        $payment = Payment::query()->where('reference', $reference)->firstOrFail();
        abort_unless($payment->isOnline(), 404);

        $checked = true;

        if ($this->shouldVerify($payment)) {
            try {
                $instance = $payment->gatewayInstance;
                $provider = $instance->gateway();

                if ($provider instanceof ChargesOnce) {
                    $settler->settle($payment, $provider->verify($instance, $reference));
                }
            } catch (Throwable $e) {
                $checked = false;
                $e instanceof GatewayError
                    ? Log::warning('billing: could not verify returning payment', ['payment_id' => $payment->id, 'provider_message' => $e->providerMessage])
                    : report($e);
            }
        }

        return response($this->views->make('billing::pay.result', ['payment' => $payment->refresh(), 'checked' => $checked]))
            ->withHeaders(PayLinkController::PRIVATE_HEADERS);
    }

    /**
     * Pending payments are always re-checked. Failed ones only while a late
     * success is plausible (declined, abandoned or unverifiable, within a
     * day), so a reference can't be used to make us call the provider
     * forever.
     */
    private function shouldVerify(Payment $payment): bool
    {
        if ($payment->status === PaymentStatus::Pending) {
            return true;
        }

        return $payment->status === PaymentStatus::Failed
            && in_array($payment->failureReason(), FailureReason::mayStillSucceed(), true)
            && $payment->failed_at !== null
            && $payment->failed_at->gt(now()->subDay());
    }
}
