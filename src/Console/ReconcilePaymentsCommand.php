<?php

declare(strict_types=1);

namespace Ivanange\Billing\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Ivanange\Billing\Contracts\ChargesOnce;
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
        // One broken payment (unregistered provider, provider outage, bad
        // data) must not stop the others from being reconciled.
        try {
            $instance = $payment->gatewayInstance;
            $provider = $instance->gateway();

            if ($provider instanceof ChargesOnce) {
                $settler->settle($payment, $provider->verify($instance, (string) $payment->reference));
            }
        } catch (Throwable $e) {
            if (! $e instanceof GatewayError) {
                report($e);
            }

            return 'errors';
        }

        if ($payment->status === PaymentStatus::Pending && $payment->created_at <= $abandonBefore) {
            $settler->abandon($payment);
        }

        return match ($payment->status) {
            PaymentStatus::Confirmed => 'confirmed',
            PaymentStatus::Failed => 'failed',
            default => 'pending',
        };
    }
}
