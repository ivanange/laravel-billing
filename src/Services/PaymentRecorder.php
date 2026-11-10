<?php

declare(strict_types=1);

namespace Ivanange\Billing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Ivanange\Billing\Contracts\Billable;
use Ivanange\Billing\Data\PaymentDraft;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Events\PaymentRecorded;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Gateways\Providers\OfflineProvider;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Models\PaymentAllocation;
use Ivanange\Billing\Models\Subscription;
use Ivanange\Billing\Periods\Span;
use Ivanange\Billing\Support\Money;

/**
 * Records a payment and the cover it buys, for one billable or a group,
 * in a single transaction. With maker-checker off the payment is confirmed
 * straight away; otherwise it waits for PaymentConfirmer.
 */
class PaymentRecorder
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Config $config,
        private readonly CoverageLedger $ledger,
        private readonly PaymentConfirmer $confirmer,
    ) {}

    public function record(PaymentDraft $draft, ?Authenticatable $by = null): Payment
    {
        $this->validate($draft);
        $confirmNow = ! $this->config->get('billing.confirmation.second_person', false);

        [$payment, $subscriptions] = $this->db->transaction(function () use ($draft, $by, $confirmNow): array {
            $this->guardReference($draft);
            $payment = $this->createPayment($draft, $by);

            foreach ($draft->billables as $billable) {
                $this->coverBillable($payment, $draft, $billable);
            }

            return [$payment, $confirmNow ? $this->confirmer->apply($payment, $by) : []];
        });

        event(new PaymentRecorded($payment));

        if ($confirmNow) {
            $this->confirmer->announce($payment, $subscriptions);
        }

        return $payment->refresh();
    }

    private function createPayment(PaymentDraft $draft, ?Authenticatable $by): Payment
    {
        $payer = $draft->payer();

        return Payment::query()->create([
            'payer_type' => $payer?->getMorphClass(),
            'payer_id' => $payer?->getKey(),
            'gateway_instance_id' => $draft->gateway->getKey(),
            'method' => $draft->method,
            'amount' => $draft->total(),
            'currency' => $draft->plan->currency,
            'status' => PaymentStatus::Recorded,
            'paid_on' => $draft->paidOn ?? CarbonImmutable::today(),
            'reference' => $this->clean($draft->reference),
            'proof_path' => $draft->proofPath,
            'notes' => $this->clean($draft->notes),
            'recorded_by' => $by?->getAuthIdentifier(),
        ]);
    }

    private function coverBillable(Payment $payment, PaymentDraft $draft, Model&Billable $billable): void
    {
        $subscription = Subscription::query()->firstOrCreate(
            [
                'plan_id' => $draft->plan->getKey(),
                'billable_type' => $billable->getMorphClass(),
                'billable_id' => $billable->getKey(),
            ],
            [
                'payer_type' => $draft->payer()?->getMorphClass(),
                'payer_id' => $draft->payer()?->getKey(),
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
     */
    /**
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
     * @param  Collection<int, CoveragePeriod>  $taken
     * @param  list<Span>  $spans
     */
    private function guardOverlap(Collection $taken, array $spans, Model&Billable $billable): void
    {
        foreach ($spans as $span) {
            foreach ($taken as $period) {
                if ($span->overlaps($period->starts_on, $period->ends_on)) {
                    throw BillingException::overlap($billable->billingName(), $period->starts_on, $period->ends_on);
                }
            }
        }
    }

    private function validate(PaymentDraft $draft): void
    {
        $this->validateBillables($draft);
        $this->validateGateway($draft);
        $this->validateAmount($draft);
    }

    private function validateBillables(PaymentDraft $draft): void
    {
        if ($draft->billables === []) {
            throw BillingException::because('no_billables');
        }

        if (! $draft->plan->is_active || $draft->plan->trashed()) {
            throw BillingException::because('plan_inactive');
        }

        $seen = [];
        foreach ($draft->billables as $billable) {
            if (! $billable instanceof Billable || ! $billable instanceof Model || ! $billable->exists) {
                throw BillingException::because('not_billable');
            }

            if (! $draft->plan->accepts($billable)) {
                throw BillingException::because('plan_does_not_accept', ['billable' => $billable->billingName()]);
            }

            $key = $billable->getMorphClass().':'.$billable->getKey();
            if (isset($seen[$key])) {
                throw BillingException::because('duplicate_billable', ['billable' => $billable->billingName()]);
            }
            $seen[$key] = true;
        }
    }

    private function validateGateway(PaymentDraft $draft): void
    {
        $gateway = $draft->gateway;

        if (! $gateway->is_active || $gateway->trashed()) {
            throw BillingException::because('gateway_inactive');
        }

        if (! $gateway->offersMethod($draft->method)) {
            throw BillingException::because('method_not_offered');
        }

        if (! $gateway->supportsCurrency($draft->plan->currency)) {
            throw BillingException::because('currency_not_supported', ['currency' => $draft->plan->currency]);
        }

        if ($draft->method === OfflineProvider::COMPLIMENTARY) {
            return;
        }

        if ($gateway->setting('require_reference') && $this->clean($draft->reference) === null) {
            throw BillingException::because('reference_required');
        }

        if ($gateway->setting('require_proof') && blank($draft->proofPath)) {
            throw BillingException::because('proof_required');
        }

    }

    /**
     * Runs inside the transaction with the gateway row locked, so two admins
     * entering the same mobile-money reference at once can't both succeed.
     */
    private function guardReference(PaymentDraft $draft): void
    {
        $reference = $this->clean($draft->reference);

        if ($reference === null || $draft->method === OfflineProvider::COMPLIMENTARY) {
            return;
        }

        GatewayInstance::query()->whereKey($draft->gateway->getKey())->lockForUpdate()->first();

        if ($this->referenceTaken($draft, $reference)) {
            throw BillingException::because('duplicate_reference', ['reference' => $reference]);
        }
    }

    private function validateAmount(PaymentDraft $draft): void
    {
        if ($draft->amountPerBillable < 0) {
            throw BillingException::because('negative_amount');
        }

        $complimentary = $draft->method === OfflineProvider::COMPLIMENTARY;

        if ($complimentary && $draft->amountPerBillable !== 0) {
            throw BillingException::because('complimentary_amount');
        }

        if (! $complimentary && $draft->amountPerBillable === 0) {
            throw BillingException::because('amount_required');
        }
    }

    private function referenceTaken(PaymentDraft $draft, string $reference): bool
    {
        return Payment::query()
            ->where('gateway_instance_id', $draft->gateway->getKey())
            ->where('reference', $reference)
            ->where('status', '!=', PaymentStatus::Void->value)
            ->exists();
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
