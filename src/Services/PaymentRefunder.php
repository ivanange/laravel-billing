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

/**
 * Full refund through the payment's own gateway instance, then the cover
 * it bought is voided. Partial refunds are not supported yet (they would
 * need a rule for which days to give back).
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

        if ($payment->status !== PaymentStatus::Confirmed) {
            throw BillingException::because('not_refundable');
        }

        $result = $provider->refund($instance, $payment);
        if (! $result->succeeded()) {
            throw GatewayError::from($result->message);
        }

        $this->db->transaction(function () use ($payment, $by): void {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== PaymentStatus::Confirmed) {
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

        event(new PaymentRefunded($payment));

        return $payment;
    }
}
