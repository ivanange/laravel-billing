<?php

declare(strict_types=1);

namespace Ivanange\Billing\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Ivanange\Billing\Contracts\ChargesOnce;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Enums\FailureReason;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\OnlinePaymentSettler;
use Throwable;

/**
 * Catches online payments whose callback and webhook never arrived:
 * re-verifies pending ones with the provider (through each payment's own
 * instance) and fails those still unpaid after the abandon window.
 * Safe to run as often as you like.
 */
class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'billing:reconcile-payments';

    protected $description = 'Re-check pending online payments with their provider and fail abandoned ones';

    /** Stored and logged when the status answer was about another payment, instead of its own message. */
    public const NOT_THIS_PAYMENT = 'The provider\'s status answer does not name this payment.';

    public function handle(OnlinePaymentSettler $settler): int
    {
        $now = CarbonImmutable::now();
        $verifyBefore = $now->subMinutes((int) config('billing.online.verify_after_minutes', 15));
        $abandonBefore = $now->subMinutes((int) config('billing.online.abandon_after_minutes', 1440));
        $counts = ['confirmed' => 0, 'failed' => 0, 'pending' => 0, 'errors' => 0];

        Payment::query()
            ->where('status', PaymentStatus::Pending->value)
            ->where('created_at', '<=', $verifyBefore)
            ->with('gatewayInstance')
            ->chunkById(100, function ($payments) use ($settler, $abandonBefore, &$counts): void {
                foreach ($payments as $payment) {
                    $counts[$this->reconcile($payment, $settler, $abandonBefore)]++;
                }
            });

        $this->info(__('billing::billing.console.reconciled', $counts));

        return self::SUCCESS;
    }

    private function reconcile(Payment $payment, OnlinePaymentSettler $settler, CarbonImmutable $abandonBefore): string
    {
        $error = null;

        // One broken payment (unregistered provider, provider outage, bad
        // data) must not stop the others from being reconciled.
        try {
            $instance = $payment->gatewayInstance;
            $provider = $instance->gateway();

            if ($provider instanceof ChargesOnce) {
                $result = $provider->verify($instance, (string) $payment->reference);

                // An answer that doesn't name this payment (refused lookup, another
                // payment's data) proves nothing: count it as a failed check. A
                // refused lookup's message ("Invalid key") explains itself; one
                // about another payment must not lend this one its "successful".
                if (! $settler->identifies($payment, $result)) {
                    $aboutAnother = $result->reference !== '' || $result->status !== ChargeStatus::Pending;

                    throw GatewayError::from($aboutAnother ? self::NOT_THIS_PAYMENT : ($result->message ?? self::NOT_THIS_PAYMENT));
                }

                $settler->settle($payment, $result);
            }
        } catch (Throwable $e) {
            $error = $this->logError($payment, $e);
        }

        // A status check that keeps failing (revoked key, IP not
        // whitelisted, unknown reference) must not keep a checkout pending
        // forever. It is failed as "unverifiable", not "abandoned", so staff
        // can find payments that may have been paid; a later verified
        // success (webhook, return page) still confirms it.
        if ($payment->status === PaymentStatus::Pending && $payment->created_at <= $abandonBefore) {
            $error === null
                ? $settler->abandon($payment)
                : $settler->abandon($payment, FailureReason::Unverifiable, ['provider_message' => $error]);
        }

        if ($error !== null) {
            return 'errors';
        }

        return match ($payment->status) {
            PaymentStatus::Confirmed => 'confirmed',
            PaymentStatus::Failed => 'failed',
            default => 'pending',
        };
    }

    /**
     * @return string the redacted error, as stored and logged
     */
    private function logError(Payment $payment, Throwable $e): string
    {
        if (! $e instanceof GatewayError) {
            report($e);
        }

        $message = $e instanceof GatewayError
            ? (string) ($e->providerMessage ?? $e->getMessage())
            : (string) GatewayError::redact($e::class.': '.$e->getMessage());

        Log::warning('billing: reconcile could not verify a payment', [
            'payment_id' => $payment->getKey(),
            'gateway_instance_id' => $payment->gateway_instance_id,
            'provider_message' => $message,
            'error' => $e instanceof GatewayError ? $e->previousClass : $e::class,
        ]);

        return $message;
    }
}
