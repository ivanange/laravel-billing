<?php

declare(strict_types=1);

namespace Ivanange\Billing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Ivanange\Billing\Contracts\Refunds;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Events\PaymentRefunded;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Models\Subscription;
use Throwable;

/**
 * Full refund through the payment's own gateway instance, then the cover
 * it bought is voided.
 *
 * The payment is moved to "refunding" under a row lock BEFORE the provider
 * is called, so two clicks or two admins can never send two refunds. If
 * the provider refuses, it goes back to "confirmed"; if the process dies
 * after the provider call, it stays "refunding" for a person to check.
 * Partial refunds are not supported (they need a rule for which days to
 * give back).
 */
class PaymentRefunder
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CoverageLedger $ledger,
    ) {}

    public function refund(Payment $payment, ?Authenticatable $by): Payment
    {
        $instance = $payment->gatewayInstance;
        $provider = $instance->gateway();

        if (! $provider instanceof Refunds) {
            throw BillingException::because('refund_not_supported');
        }

        $this->transition($payment, PaymentStatus::Confirmed, PaymentStatus::Refunding);

        try {
            $result = $provider->refund($instance, $payment);
        } catch (Throwable $e) {
            $this->transition($payment, PaymentStatus::Refunding, PaymentStatus::Confirmed);

            throw $e;
        }

        if (! $result->succeeded()) {
            $this->transition($payment, PaymentStatus::Refunding, PaymentStatus::Confirmed);

            throw GatewayError::from($result->message);
        }

        $this->complete($payment, $by);
        event(new PaymentRefunded($payment));

        return $payment;
    }

    private function transition(Payment $payment, PaymentStatus $from, PaymentStatus $to): void
    {
        $this->db->transaction(function () use ($payment, $from, $to): void {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== $from) {
                throw BillingException::because('not_refundable');
            }

            $locked->forceFill(['status' => $to])->save();
            $payment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function complete(Payment $payment, ?Authenticatable $by): void
    {
        $this->db->transaction(function () use ($payment, $by): void {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== PaymentStatus::Refunding) {
                // Someone changed it meanwhile; never overwrite their state.
                throw BillingException::because('not_refundable');
            }

            $locked->forceFill([
                'status' => PaymentStatus::Refunded,
                'refunded_at' => CarbonImmutable::now(),
                'refunded_by' => $by?->getAuthIdentifier(),
            ])->save();

            $periods = $locked->coveragePeriods()->get();
            CoveragePeriod::query()->whereKey($periods->modelKeys())->update(['status' => PeriodStatus::Void->value]);
            Subscription::query()
                ->whereKey($periods->pluck('subscription_id')->unique()->all())
                ->get()
                ->each(fn (Subscription $subscription) => $this->ledger->refresh($subscription));

            $payment->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
