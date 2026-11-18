<?php

declare(strict_types=1);

namespace Ivanange\Billing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Ivanange\Billing\Contracts\Billable;
use Ivanange\Billing\Data\PaymentDraft;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Models\PaymentAllocation;
use Ivanange\Billing\Models\Subscription;
use Ivanange\Billing\Periods\Span;
use Ivanange\Billing\Support\Money;

/**
 * Creates the pending coverage periods a payment buys and allocates the
 * payment to them. Shared by offline recording and online confirmation;
 * call it inside the caller's transaction.
 */
class CoverageAllocator
{
    public function __construct(private readonly CoverageLedger $ledger) {}

    public function allocate(Payment $payment, PaymentDraft $draft): void
    {
        foreach ($draft->billables as $billable) {
            $this->coverBillable($payment, $draft, $billable);
        }
    }

    private function coverBillable(Payment $payment, PaymentDraft $draft, Model&Billable $billable): void
    {
        $payer = $draft->subscriptionPayer($billable);
        $subscription = Subscription::query()->firstOrCreate(
            [
                'plan_id' => $draft->plan->getKey(),
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
            ],
            [
                'payer_type' => $payer->getMorphClass(),
                'payer_id' => $payer->getKey(),
                'gateway_instance_id' => $draft->gateway->getKey(),
            ],
        );
        $subscription = Subscription::query()->lockForUpdate()->findOrFail($subscription->getKey());

        // Locking read: under MySQL REPEATABLE READ a plain read would use the
        // transaction's old snapshot and miss periods another admin just
        // committed for the same billable.
        $taken = $subscription->coveragePeriods()->getQuery()
            ->whereIn('status', PeriodStatus::occupying())
            ->lockForUpdate()
            ->get(['starts_on', 'ends_on']);

        $spans = $draft->term->spans($draft->startsOn ?? $this->defaultStart($subscription, $taken));
        if (! $draft->allowOverlap) {
            $this->guardOverlap($taken, $spans, $billable);
        }

        $shares = Money::split($draft->amountPerBillable, count($spans));

        foreach ($spans as $index => $span) {
            $period = CoveragePeriod::query()->create([
                'subscription_id' => $subscription->getKey(),
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
                'starts_on' => $span->startsOn,
                'ends_on' => $span->endsOn,
                'grace_ends_on' => $this->ledger->graceEnd($draft->plan, $span->endsOn),
                'label' => $span->label,
                'amount' => $shares[$index],
                'currency' => $draft->plan->currency,
                'status' => PeriodStatus::Pending,
            ]);

            PaymentAllocation::query()->create([
                'payment_id' => $payment->getKey(),
                'coverage_period_id' => $period->getKey(),
                'amount' => $shares[$index],
            ]);
        }
    }

    /**
     * Continue right after the last reserved day while the cover is still
     * running or in grace; after a lapse, start today (no back-paying gaps).
     *
     * @param  Collection<int, CoveragePeriod>  $taken
     */
    private function defaultStart(Subscription $subscription, Collection $taken): CarbonImmutable
    {
        $today = CarbonImmutable::today();
        $last = $taken->max(fn (CoveragePeriod $period) => $period->ends_on);
        $next = $last?->addDay();

        if ($next !== null && $next->addDays((int) $subscription->plan->grace_days)->gte($today)) {
            return $next;
        }

        return $today;
    }

    /**
     * Each new span is checked against the existing periods and against the
     * spans before it in this payment (e.g. two calendar periods that share
     * days).
     *
     * @param  Collection<int, CoveragePeriod>  $taken
     * @param  list<Span>  $spans
     */
    private function guardOverlap(Collection $taken, array $spans, Model&Billable $billable): void
    {
        foreach ($spans as $i => $span) {
            foreach ($taken as $period) {
                if ($span->overlaps($period->starts_on, $period->ends_on)) {
                    throw BillingException::overlap($billable->billingName(), $period->starts_on, $period->ends_on);
                }
            }

            foreach (array_slice($spans, 0, $i) as $earlier) {
                if ($span->overlaps($earlier->startsOn, $earlier->endsOn)) {
                    throw BillingException::because('overlap_in_payment', [
                        'billable' => $billable->billingName(),
                        'start' => max($span->startsOn, $earlier->startsOn)->format('Y-m-d'),
                        'end' => min($span->endsOn, $earlier->endsOn)->format('Y-m-d'),
                    ]);
                }
            }
        }
    }
}
