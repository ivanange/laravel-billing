<?php

declare(strict_types=1);

namespace Ivanange\Billing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Log;
use Ivanange\Billing\Contracts\Billable;
use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Data\PaymentDraft;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Events\PaymentFailed;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Models\Plan;
use Ivanange\Billing\Models\PlanPrice;
use Ivanange\Billing\Models\Subscription;

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
        if ($result->reference !== $payment->reference) {
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

        if ($result->failed()) {
            if ($payment->status === PaymentStatus::Failed) {
                return [null, []];
            }

            $this->fail($payment, 'declined', ['provider_message' => $result->message]);

            return ['failed', []];
        }

        if ($result->amount !== $payment->amount || strtoupper((string) $result->currency) !== strtoupper($payment->currency)) {
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

        $billable = is_a($class, Model::class, true) ? $class::query()->find($checkout['billable_id'] ?? null) : null;
        $plan = Plan::query()->withTrashed()->find($checkout['plan_id'] ?? null);
        $price = PlanPrice::query()->find($checkout['plan_price_id'] ?? null);

        if (! $billable instanceof Billable || $plan === null || $price === null || $price->isCalendar()) {
            return null;
        }

        return new PaymentDraft(
            billables: [$billable],
            plan: $plan,
            term: $price->durationTerm(),
            gateway: $payment->gatewayInstance,
            method: $payment->method,
            amountPerBillable: $payment->amount,
            payer: $payment->payer,
        );
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
