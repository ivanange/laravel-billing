<?php

declare(strict_types=1);

namespace Ivanange\Billing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Events\CoverageExtended;
use Ivanange\Billing\Events\PaymentConfirmed;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Models\Subscription;
use Ivanange\Billing\Services\Concerns\DispatchesAfterCommit;

/**
 * Turns a recorded payment into cover. Business rules only; whether the
 * user may confirm at all is the caller's Gate check (billing.confirm).
 */
class PaymentConfirmer
{
    use DispatchesAfterCommit;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Config $config,
        private readonly CoverageLedger $ledger,
    ) {}

    public function confirm(Payment $payment, ?Authenticatable $by): Payment
    {
        [$locked, $subscriptions] = $this->db->transaction(function () use ($payment, $by): array {
            // Lock the row so a concurrent void or second confirm waits and
            // then sees the new status instead of overwriting it.
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            $this->guard($locked, $by);

            return [$locked, $this->apply($locked, $by)];
        });

        $payment->setRawAttributes($locked->getAttributes(), true);
        $this->afterCommit(fn () => $this->announce($payment, $subscriptions));

        return $payment;
    }

    /**
     * Confirm inside a caller's transaction, skipping the second-person rule
     * (used when maker-checker is off). Returns the subscriptions touched;
     * call announce() once the outermost transaction has committed.
     *
     * @return list<Subscription>
     */
    public function apply(Payment $payment, ?Authenticatable $by): array
    {
        $payment->forceFill([
            'status' => PaymentStatus::Confirmed,
            'confirmed_by' => $by?->getAuthIdentifier(),
            'confirmed_at' => CarbonImmutable::now(),
        ])->save();

        $periods = $payment->coveragePeriods()->get();
        CoveragePeriod::query()
            ->whereKey($periods->modelKeys())
            ->where('status', PeriodStatus::Pending->value)
            ->update(['status' => PeriodStatus::Paid->value]);

        return Subscription::query()
            ->whereKey($periods->pluck('subscription_id')->unique()->all())
            ->get()
            ->map(fn (Subscription $subscription): Subscription => $this->ledger->refresh($subscription))
            ->all();
    }

    /**
     * @param  list<Subscription>  $subscriptions
     */
    public function announce(Payment $payment, array $subscriptions): void
    {
        event(new PaymentConfirmed($payment));

        foreach ($subscriptions as $subscription) {
            event(new CoverageExtended($subscription));
        }
    }

    private function guard(Payment $payment, ?Authenticatable $by): void
    {
        if ($payment->status !== PaymentStatus::Recorded) {
            throw BillingException::because('not_awaiting_confirmation');
        }

        if (! $this->config->get('billing.confirmation.second_person', false)) {
            return;
        }

        if ($by === null) {
            throw BillingException::because('confirmer_required');
        }

        if ((string) $by->getAuthIdentifier() === (string) $payment->recorded_by) {
            throw BillingException::because('self_confirmation');
        }
    }
}
