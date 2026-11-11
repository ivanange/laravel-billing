<?php

declare(strict_types=1);

namespace Ivanange\Billing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Log;
use Ivanange\Billing\Contracts\Billable;
use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Data\PaymentDraft;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Enums\DurationUnit;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Events\PaymentFailed;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Models\Plan;
use Ivanange\Billing\Models\Subscription;
use Ivanange\Billing\Periods\DurationTerm;

/**
 * Applies a provider's answer (verify call or webhook) to an online
 * payment, exactly once.
 *
 * The payment row is locked for the whole decision, so a callback and a
 * webhook arriving together, or a provider retrying a webhook, confirm the
 * payment once and create one set of cover. The provider's amount and
 * currency must equal the stored payment before any cover is given.
 */
class OnlinePaymentSettler
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CoverageAllocator $allocator,
        private readonly PaymentConfirmer $confirmer,
    ) {}

    public function settle(Payment $payment, ChargeResult $result): Payment
    {
        if (! in_array($result->reference, array_filter([$payment->reference, $payment->external_id]), true)) {
            Log::warning('billing: result reference does not match payment', ['payment_id' => $payment->getKey()]);

            return $payment;
        }

        /** @var array{0: Payment, 1: ?string, 2: list<Subscription>} $outcome */
        $outcome = $this->db->transaction(function () use ($payment, $result): array {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

            return [$locked, ...$this->decide($locked, $result)];
        });

        [$locked, $event, $subscriptions] = $outcome;
        $payment->setRawAttributes($locked->getAttributes(), true);

        if ($event === 'confirmed') {
            $this->confirmer->announce($payment, $subscriptions);
        } elseif ($event === 'failed') {
            event(new PaymentFailed($payment));
        }

        return $payment;
    }

    /**
     * Fail a payment the payer never completed. No-op unless still pending.
     */
    public function abandon(Payment $payment): Payment
    {
        $failed = $this->db->transaction(function () use ($payment): bool {
            $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== PaymentStatus::Pending) {
                return false;
            }

            $this->fail($locked, 'abandoned');
            $payment->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($failed) {
            event(new PaymentFailed($payment));
        }

        return $payment;
    }

    /**
     * @return array{0: ?string, 1: list<Subscription>} event to fire, subscriptions touched
     */
    private function decide(Payment $payment, ChargeResult $result): array
    {
        // Final states never change: duplicate webhooks and callback/webhook
        // races end here. A failed payment may still be confirmed by a later
        // verified success (the money was taken), never the other way round.
        $open = in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Failed], true);

        if (! $open || $result->status === ChargeStatus::Pending) {
            return [null, []];
        }

        $mismatch = $result->amount !== $payment->amount || strtoupper((string) $result->currency) !== strtoupper($payment->currency);

        // Already failed: only a matching verified success may still change
        // it, so provider retries never fire PaymentFailed twice.
        if ($payment->status === PaymentStatus::Failed && ($result->failed() || $mismatch)) {
            return [null, []];
        }

        if ($result->failed()) {
            $this->fail($payment, 'declined', ['provider_message' => $result->message]);

            return ['failed', []];
        }

        if ($mismatch) {
            Log::warning('billing: provider amount or currency differs from the payment', [
                'payment_id' => $payment->getKey(),
                'expected' => [$payment->amount, $payment->currency],
                'reported' => [$result->amount, $result->currency],
            ]);
            $this->fail($payment, 'amount_mismatch', ['reported' => ['amount' => $result->amount, 'currency' => $result->currency]]);

            return ['failed', []];
        }

        return ['confirmed', $this->confirm($payment, $result)];
    }

    /**
     * @return list<Subscription>
     */
    private function confirm(Payment $payment, ChargeResult $result): array
    {
        $payment->forceFill([
            'external_id' => $result->externalId ?? $payment->external_id,
            'paid_on' => CarbonImmutable::today(),
            'failed_at' => null,
            'failure_reason' => null,
            'meta' => array_diff_key((array) $payment->meta, ['failure' => true]),
        ])->save();

        $draft = $this->draft($payment);

        if ($draft === null) {
            // Money was taken but what it paid for is gone (deleted billable
            // or plan): confirm the payment, flag it for a person.
            Log::warning('billing: confirmed online payment has nothing to cover', ['payment_id' => $payment->getKey()]);
            $payment->forceFill(['meta' => array_merge((array) $payment->meta, ['unallocated' => true])])->save();
        } else {
            $this->allocator->allocate($payment, $draft);
        }

        return $this->confirmer->apply($payment, null);
    }

    private function draft(Payment $payment): ?PaymentDraft
    {
        $checkout = (array) data_get($payment->meta, 'checkout', []);
        $class = Relation::getMorphedModel((string) ($checkout['billable_type'] ?? '')) ?? (string) ($checkout['billable_type'] ?? '');

        $billable = is_a($class, Model::class, true) ? $this->findBillable($class, $checkout['billable_id'] ?? null) : null;
        $plan = Plan::query()->withTrashed()->find($checkout['plan_id'] ?? null);
        $unit = DurationUnit::tryFrom((string) ($checkout['duration_unit'] ?? ''));

        if (! $billable instanceof Billable || $plan === null || $unit === null) {
            return null;
        }

        // The term was snapshotted at checkout: editing or deleting the price
        // afterwards never changes what an in-flight payment buys.
        return new PaymentDraft(
            billables: [$billable],
            plan: $plan,
            term: new DurationTerm($unit, max(1, (int) ($checkout['duration_count'] ?? 1))),
            gateway: $payment->gatewayInstance,
            method: $payment->method,
            amountPerBillable: $payment->amount,
            payer: $payment->payer,
        );
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function findBillable(string $class, mixed $id): ?Model
    {
        $query = $class::query();

        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->withoutGlobalScope(SoftDeletingScope::class);
        }

        return is_scalar($id) ? $query->find($id) : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function fail(Payment $payment, string $reason, array $meta = []): void
    {
        $payment->forceFill([
            'status' => PaymentStatus::Failed,
            'failed_at' => CarbonImmutable::now(),
            'failure_reason' => $reason,
            'meta' => array_merge((array) $payment->meta, array_filter(['failure' => $meta ?: null])),
        ])->save();
    }
}
