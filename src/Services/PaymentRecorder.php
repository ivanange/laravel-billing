<?php

declare(strict_types=1);

namespace Ivanange\Billing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Ivanange\Billing\Contracts\Billable;
use Ivanange\Billing\Contracts\ManualConfirmation;
use Ivanange\Billing\Data\PaymentDraft;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Events\PaymentRecorded;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Gateways\Providers\OfflineProvider;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\Concerns\DispatchesAfterCommit;

/**
 * Records a payment and the cover it buys, for one billable or a group,
 * in a single transaction. With maker-checker off the payment is confirmed
 * straight away; otherwise it waits for PaymentConfirmer.
 */
class PaymentRecorder
{
    use DispatchesAfterCommit;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Config $config,
        private readonly CoverageAllocator $allocator,
        private readonly PaymentConfirmer $confirmer,
    ) {}

    public function record(PaymentDraft $draft, ?Authenticatable $by = null): Payment
    {
        $this->validate($draft);
        $confirmNow = ! $this->config->get('billing.confirmation.second_person', false);

        [$payment, $subscriptions] = $this->db->transaction(function () use ($draft, $by, $confirmNow): array {
            $this->guardReference($draft);
            $payment = $this->createPayment($draft, $by);

            $this->allocator->allocate($payment, $draft);

            return [$payment, $confirmNow ? $this->confirmer->apply($payment, $by) : []];
        });

        $this->afterCommit(function () use ($payment, $confirmNow, $subscriptions): void {
            event(new PaymentRecorded($payment));

            if ($confirmNow) {
                $this->confirmer->announce($payment, $subscriptions);
            }
        });

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
            if (! $billable->exists) {
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

        if (! $gateway->gateway() instanceof ManualConfirmation) {
            throw BillingException::because('not_offline');
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
