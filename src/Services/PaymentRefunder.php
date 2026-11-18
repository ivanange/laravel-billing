<?php

declare(strict_types=1);

namespace Ivanange\Billing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Log;
use Ivanange\Billing\Contracts\Refunds;
use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Events\PaymentRefunded;
use Ivanange\Billing\Events\StuckRefundResolved;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Exceptions\GatewayOutcomeUnknown;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Models\Subscription;
use Ivanange\Billing\Services\Concerns\DispatchesAfterCommit;
use Throwable;

/**
 * Full refund through the payment's own gateway instance, then the cover
 * it bought is voided.
 *
 * The payment is moved to "refunding" under a row lock BEFORE the provider
 * is called, so two clicks or two admins can never send two refunds. Only
 * a definite refusal from the provider puts it back to "confirmed". When
 * the outcome is unknown (timeout, dropped connection, 5xx, unreadable
 * answer, or the process dies after the call) it stays "refunding" until a
 * person checks with the provider and calls resolveStuck(). Partial
 * refunds are not supported (they need a rule for which days to give
 * back).
 */
class PaymentRefunder
{
    use DispatchesAfterCommit;

    /**
     * Added to billing.online.http_timeout before a stuck refund may be
     * settled by hand. The HTTP timeout bounds the call itself; this covers
     * the rest of a refund request (building the call, waiting for the row
     * lock to record the answer) and clock differences between the server
     * that started the refund and the one settling it.
     */
    public const RESOLVE_GRACE_SECONDS = 60;

    public const REFUNDED = 'refunded';

    public const NOT_REFUNDED = 'not_refunded';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly CoverageLedger $ledger,
        private readonly Config $config,
    ) {}

    public function refund(Payment $payment, ?Authenticatable $by): Payment
    {
        $instance = $payment->gatewayInstance;
        $provider = $instance->gateway();

        if (! $provider instanceof Refunds) {
            throw BillingException::because('refund_not_supported');
        }

        $this->transition($payment, PaymentStatus::Confirmed, PaymentStatus::Refunding, ['refund_started_at' => CarbonImmutable::now()]);

        try {
            $result = $provider->refund($instance, $payment);
        } catch (GatewayOutcomeUnknown $e) {
            throw $this->outcomeUnknown($payment, $e->providerMessage, $e->previousClass);
        } catch (BillingException $e) {
            // Refused by the provider, or never sent (misconfigured instance).
            $this->transition($payment, PaymentStatus::Refunding, PaymentStatus::Confirmed);

            throw $e;
        } catch (Throwable $e) {
            report($e);

            throw $this->outcomeUnknown($payment, GatewayError::redact($e->getMessage()), $e::class);
        }

        if ($result->failed()) {
            $this->transition($payment, PaymentStatus::Refunding, PaymentStatus::Confirmed);

            throw GatewayError::from($result->message);
        }

        if (! $result->succeeded()) {
            throw $this->outcomeUnknown($payment, GatewayError::redact($result->message), null);
        }

        try {
            $this->complete($payment, $by);
        } catch (Throwable $e) {
            throw $this->acceptedButNotRecorded($payment, $result, $e);
        }

        $this->afterCommit(fn () => event(new PaymentRefunded($payment)));

        return $payment;
    }

    /**
     * Settle a payment left in "refunding" once a person has checked with
     * the provider: $refunded true completes the refund (cover voided),
     * false puts it back to "confirmed". Never calls the provider. Refused
     * while the refund call that started it may still be running.
     */
    public function resolveStuck(Payment $payment, bool $refunded, ?Authenticatable $by): Payment
    {
        $resolution = [
            'refund_resolved_at' => CarbonImmutable::now(),
            'refund_resolved_by' => $by?->getAuthIdentifier(),
            'refund_resolution' => $refunded ? self::REFUNDED : self::NOT_REFUNDED,
        ];

        if ($refunded) {
            $this->complete($payment, $by, $resolution, byHand: true);
        } else {
            $this->transition($payment, PaymentStatus::Refunding, PaymentStatus::Confirmed, $resolution, byHand: true);
        }

        Log::info('billing: stuck refund settled by hand', [
            'payment_id' => $payment->getKey(),
            'refunded' => $refunded,
            'by' => $by?->getAuthIdentifier(),
        ]);

        $this->afterCommit(function () use ($payment, $refunded, $by): void {
            event(new StuckRefundResolved($payment, $refunded, $by));

            if ($refunded) {
                event(new PaymentRefunded($payment));
            }
        });

        return $payment;
    }

    /**
     * When a stuck refund may be settled by hand: once the call that put it
     * in "refunding" must have ended. Rows from before refund_started_at
     * existed have no time and may be settled at once.
     */
    public function resolvableAt(Payment $payment): ?CarbonImmutable
    {
        return $payment->refund_started_at?->addSeconds((int) $this->config->get('billing.online.http_timeout', 30) + self::RESOLVE_GRACE_SECONDS);
    }

    private function outcomeUnknown(Payment $payment, ?string $providerMessage, ?string $errorClass): BillingException
    {
        Log::warning('billing: refund outcome unknown, payment left refunding', [
            'payment_id' => $payment->getKey(),
            'gateway_instance_id' => $payment->gateway_instance_id,
            'provider_message' => $providerMessage,
            'error' => $errorClass,
        ]);

        return BillingException::because('refund_outcome_unknown');
    }

    /**
     * The provider returned the money but recording it failed (the row
     * changed meanwhile, or the database failed). The payment is put back
     * in "refunding" when it reads "confirmed", so it is counted with the
     * stuck refunds and settled by a person, never left looking paid.
     */
    private function acceptedButNotRecorded(Payment $payment, ChargeResult $result, Throwable $e): BillingException
    {
        $accepted = [
            'provider_refund_id' => GatewayError::redact($result->externalId),
            'provider_message' => GatewayError::redact($result->message),
            'at' => CarbonImmutable::now()->toIso8601String(),
        ];

        Log::error('billing: the provider accepted a refund that could not be recorded', [
            'payment_id' => $payment->getKey(),
            'gateway_instance_id' => $payment->gateway_instance_id,
            ...$accepted,
            'error' => $e::class,
            'error_message' => GatewayError::redact($e->getMessage()),
        ]);
        report($e);

        try {
            $this->db->transaction(function () use ($payment, $accepted): void {
                $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
                $locked->forceFill(['meta' => array_merge((array) $locked->meta, ['refund_accepted' => $accepted])]);

                if ($locked->status === PaymentStatus::Confirmed) {
                    $locked->forceFill(['status' => PaymentStatus::Refunding]);
                }

                $locked->save();
                $payment->setRawAttributes($locked->getAttributes(), true);
            });
        } catch (Throwable $flagError) {
            report($flagError);
        }

        return BillingException::because('refund_sent_not_recorded');
    }

    /**
     * @param  array<string, mixed>  $changes  saved with the new status
     */
    private function transition(Payment $payment, PaymentStatus $from, PaymentStatus $to, array $changes = [], bool $byHand = false): void
    {
        $this->db->transaction(function () use ($payment, $from, $to, $changes, $byHand): void {
            $locked = $this->lock($payment, $from, $byHand);

            $locked->forceFill(['status' => $to, ...$changes])->save();
            $payment->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * @param  array<string, mixed>  $changes  saved with the refunded status
     */
    private function complete(Payment $payment, ?Authenticatable $by, array $changes = [], bool $byHand = false): void
    {
        $this->db->transaction(function () use ($payment, $by, $changes, $byHand): void {
            $locked = $this->lock($payment, PaymentStatus::Refunding, $byHand);

            $locked->forceFill([
                'status' => PaymentStatus::Refunded,
                'refunded_at' => CarbonImmutable::now(),
                'refunded_by' => $by?->getAuthIdentifier(),
                ...$changes,
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

    /**
     * Lock the row and check it is still in the state the caller expects:
     * someone may have changed it meanwhile, and their state is never
     * overwritten. Settling by hand also waits until the refund call can no
     * longer be running.
     */
    private function lock(Payment $payment, PaymentStatus $from, bool $byHand): Payment
    {
        $locked = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->status !== $from) {
            throw BillingException::because($byHand ? 'not_refunding' : 'not_refundable');
        }

        $resolvableAt = $byHand ? $this->resolvableAt($locked) : null;
        if ($resolvableAt !== null && $resolvableAt->isFuture()) {
            throw BillingException::because('refund_may_be_in_progress', [
                'seconds' => (int) ceil(CarbonImmutable::now()->diffInSeconds($resolvableAt)),
            ]);
        }

        return $locked;
    }
}
