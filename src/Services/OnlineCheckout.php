<?php

declare(strict_types=1);

namespace Ivanange\Billing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ivanange\Billing\Contracts\ChargesOnce;
use Ivanange\Billing\Data\CheckoutRequest;
use Ivanange\Billing\Data\PaymentIntent;
use Ivanange\Billing\Data\StartedCharge;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Events\PaymentFailed;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Gateways\Omnipay\OmnipayProvider;
use Ivanange\Billing\Models\Payment;
use Throwable;

/**
 * Starts a hosted online payment: stores a pending Payment (no cover yet)
 * and asks the provider for the checkout URL. Cover is created only when
 * the provider confirms (OnlinePaymentSettler), so an abandoned checkout
 * reserves nothing.
 */
class OnlineCheckout
{
    public function start(CheckoutRequest $request): StartedCharge
    {
        $provider = $this->validate($request);
        $payment = $this->createPending($request);

        try {
            $started = $provider->start($request->gateway, $this->intent($request, $payment));
        } catch (Throwable $e) {
            $this->markStartFailed($payment, $e);

            throw $e instanceof BillingException ? $e : GatewayError::from($e->getMessage(), $e);
        }

        if ($started->externalId !== null) {
            $payment->forceFill(['external_id' => $started->externalId])->save();
        }

        return $started;
    }

    private function validate(CheckoutRequest $request): ChargesOnce
    {
        $gateway = $request->gateway;
        $provider = $gateway->gateway();

        if (! $provider instanceof ChargesOnce) {
            throw BillingException::because('not_online');
        }

        match (true) {
            ! $gateway->is_active || $gateway->trashed() => throw BillingException::because('gateway_inactive'),
            ! $gateway->offersMethod($request->method) => throw BillingException::because('method_not_offered'),
            ! $gateway->supportsCurrency($request->plan->currency) => throw BillingException::because('currency_not_supported', ['currency' => $request->plan->currency]),
            ! $request->plan->is_active || $request->plan->trashed() => throw BillingException::because('plan_inactive'),
            ! $request->plan->accepts($request->billable) => throw BillingException::because('plan_does_not_accept', ['billable' => $request->billable->billingName()]),
            (int) $request->price->plan_id !== (int) $request->plan->getKey() => throw BillingException::because('price_not_in_plan'),
            $request->price->isCalendar() => throw BillingException::because('calendar_price_offline_only'),
            (int) $request->price->amount <= 0 => throw BillingException::because('amount_required'),
            default => null,
        };

        $fields = $provider instanceof OmnipayProvider ? $provider::payerFields() : [];
        if (in_array('email', $fields, true) && filter_var($request->email, FILTER_VALIDATE_EMAIL) === false) {
            throw BillingException::because('email_required');
        }

        if (in_array('phone', $fields, true) && blank($request->phone)) {
            throw BillingException::because('phone_required');
        }

        return $provider;
    }

    private function createPending(CheckoutRequest $request): Payment
    {
        $payer = $request->payer ?? $request->billable;

        return Payment::query()->create([
            'payer_type' => $payer->getMorphClass(),
            'payer_id' => $payer->getKey(),
            'gateway_instance_id' => $request->gateway->getKey(),
            'method' => $request->method,
            'amount' => (int) $request->price->amount,
            'currency' => $request->plan->currency,
            'status' => PaymentStatus::Pending,
            'paid_on' => CarbonImmutable::today(),
            'reference' => 'BIL-'.Str::upper((string) Str::ulid()),
            'meta' => ['checkout' => [
                'billable_type' => $request->billable->getMorphClass(),
                'billable_id' => $request->billable->getKey(),
                'plan_id' => $request->plan->getKey(),
                'plan_price_id' => $request->price->getKey(),
                // Snapshot: what this payment buys, fixed at checkout.
                'duration_unit' => $request->price->duration_unit?->value,
                'duration_count' => (int) $request->price->duration_count,
                'price_label' => $request->price->label,
            ]],
        ]);
    }

    private function intent(CheckoutRequest $request, Payment $payment): PaymentIntent
    {
        return new PaymentIntent(
            reference: (string) $payment->reference,
            amount: $payment->amount,
            currency: $payment->currency,
            method: $payment->method,
            returnUrl: route('billing.pay.return', ['reference' => $payment->reference]),
            email: $request->email,
            phone: $request->phone,
            description: Str::limit($request->plan->name.' – '.$request->price->label, 100, ''),
            metadata: ['payment_id' => $payment->getKey()],
        );
    }

    private function markStartFailed(Payment $payment, Throwable $e): void
    {
        $payment->forceFill([
            'status' => PaymentStatus::Failed,
            'failed_at' => CarbonImmutable::now(),
            'failure_reason' => 'start_failed',
        ])->save();

        Log::warning('billing: checkout could not be started', [
            'payment_id' => $payment->getKey(),
            'gateway_instance_id' => $payment->gateway_instance_id,
            'provider_message' => $e instanceof GatewayError ? $e->providerMessage : $e::class,
        ]);

        event(new PaymentFailed($payment));
    }
}
