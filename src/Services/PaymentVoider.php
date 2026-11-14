<?php

declare(strict_types=1);

namespace Ivanange\Billing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Events\PaymentVoided;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Models\Subscription;

/**
 * Cancels a wrong or rejected payment and the cover it bought. Nothing is
 * deleted: the payment keeps who voided it, when and why.
 */
class PaymentVoider
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CoverageLedger $ledger,
    ) {}

    /**
     * @return list<PaymentStatus>
     */
    public static function voidable(): array
    {
        return [PaymentStatus::Recorded, PaymentStatus::Confirmed];
    }

    public function void(Payment $payment, string $reason, ?Authenticatable $by): Payment
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw BillingException::because('void_reason_required');
        }

        $this->db->transaction(function () use (&$payment, $reason, $by): void {
            // Locked so a concurrent confirm can't overwrite the void (or vice versa).
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === PaymentStatus::Void) {
                throw BillingException::because('already_void');
            }

            // Pending online payments may still succeed at the provider (a void
            // would then swallow real money), failed ones have nothing to void,
            // and refunds own the Refunding/Refunded states.
            if (! in_array($locked->status, self::voidable(), true)) {
                throw BillingException::because('not_voidable');
            }

            $payment->setRawAttributes($locked->getAttributes(), true);
            $payment->forceFill([
                'status' => PaymentStatus::Void,
                'voided_by' => $by?->getAuthIdentifier(),
                'voided_at' => CarbonImmutable::now(),
                'void_reason' => $reason,
            ])->save();

            $periods = $payment->coveragePeriods()->get();
            CoveragePeriod::query()->whereKey($periods->modelKeys())->update(['status' => PeriodStatus::Void->value]);

            Subscription::query()
                ->whereKey($periods->pluck('subscription_id')->unique()->all())
                ->get()
                ->each(fn (Subscription $subscription) => $this->ledger->refresh($subscription));
        });

        event(new PaymentVoided($payment));

        return $payment;
    }
}
