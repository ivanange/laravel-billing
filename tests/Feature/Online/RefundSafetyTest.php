<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature\Online;

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Data\CheckoutRequest;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Events\PaymentRefunded;
use Ivanange\Billing\Events\StuckRefundResolved;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Exceptions\GatewayOutcomeUnknown;
use Ivanange\Billing\Gateways\Providers\NotchPayProvider;
use Ivanange\Billing\Gateways\Providers\PaystackProvider;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\OnlineCheckout;
use Ivanange\Billing\Services\OnlinePaymentSettler;
use Ivanange\Billing\Services\PaymentRefunder;
use Ivanange\Billing\Tests\Support\OnlineHelpers;
use Ivanange\Billing\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * A refund the provider may have made is never lost, and a person can only
 * settle a stuck refund once no call to the provider can still be running.
 */
class RefundSafetyTest extends TestCase
{
    use OnlineHelpers;

    private const QUEUED = ['status' => true, 'message' => 'Refund has been queued for processing', 'data' => ['id' => 3018284, 'amount' => 1500, 'currency' => 'GHS']];

    private function confirmedPaystack(): Payment
    {
        $payment = $this->startCheckout();
        app(OnlinePaymentSettler::class)->settle($payment, new ChargeResult(ChargeStatus::Succeeded, (string) $payment->reference, 1500, 'GHS'));

        return $payment;
    }

    private function confirmedNotchPay(): Payment
    {
        $price = $this->monthlyPrice($this->plan(['currency' => 'XAF']), 5000);
        Http::fake(['api.notchpay.co/payments' => Http::response([
            'status' => 'Accepted', 'code' => 201,
            'transaction' => ['id' => 'pay_123', 'reference' => 'trx.abc123', 'status' => 'pending'],
            'authorization_url' => 'https://pay.notchpay.co/pay_123',
        ], 201)]);
        app(OnlineCheckout::class)->start(new CheckoutRequest($this->member(), $price->plan, $price, $this->notchpay(), NotchPayProvider::MTN, phone: '+237670000000'));
        $payment = Payment::query()->latest('id')->firstOrFail();
        app(OnlinePaymentSettler::class)->settle($payment, new ChargeResult(ChargeStatus::Succeeded, (string) $payment->reference, 5000, 'XAF'));

        return $payment;
    }

    /** Seconds after the refund call started before a person may settle it. */
    private function window(): int
    {
        return (int) config('billing.online.http_timeout') + PaymentRefunder::RESOLVE_GRACE_SECONDS;
    }

    private function refundTimesOut(Payment $payment): void
    {
        Http::fake(['api.paystack.co/refund' => Http::failedConnection('Operation timed out')]);

        try {
            app(PaymentRefunder::class)->refund($payment, null);
            $this->fail('Expected the unknown outcome to be reported');
        } catch (BillingException) {
        }
    }

    // A stuck refund is only settled once the provider call is over

    public function test_entering_refunding_records_when_the_refund_call_started(): void
    {
        $payment = $this->confirmedPaystack();

        $this->refundTimesOut($payment);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Refunding, $payment->status);
        $this->assertTrue(CarbonImmutable::now()->equalTo($payment->refund_started_at));
    }

    public function test_a_refund_still_in_flight_cannot_be_settled_by_another_admin(): void
    {
        $payment = $this->confirmedPaystack();
        $refusal = null;

        // Admin B tries to settle it while admin A's refund call is still waiting for the provider.
        Http::fake(['api.paystack.co/refund' => function () use ($payment, &$refusal) {
            try {
                app(PaymentRefunder::class)->resolveStuck(Payment::query()->findOrFail($payment->id), false, $this->user('Admin B'));
            } catch (BillingException $e) {
                $refusal = $e->getMessage();
            }

            return Http::response(self::QUEUED);
        }]);

        app(PaymentRefunder::class)->refund($payment, $this->user('Admin A'));

        $this->assertSame('This refund may still be in progress with the provider. Try again in '.$this->window().' seconds.', $refusal);
        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
        $this->assertSame(PeriodStatus::Void, $payment->coveragePeriods()->sole()->status);
        $this->assertNull($payment->refund_resolved_at);
    }

    public function test_a_stuck_refund_can_be_settled_once_the_call_must_have_ended(): void
    {
        $payment = $this->confirmedPaystack();
        $this->refundTimesOut($payment);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($this->window() - 10));
        try {
            app(PaymentRefunder::class)->resolveStuck($payment->refresh(), false, null);
            $this->fail('Settled while the refund call may still be running');
        } catch (BillingException $e) {
            $this->assertStringContainsString('Try again in 10 seconds.', $e->getMessage());
        }
        $this->assertSame(PaymentStatus::Refunding, $payment->refresh()->status);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(10));
        app(PaymentRefunder::class)->resolveStuck($payment, false, null);

        $this->assertSame(PaymentStatus::Confirmed, $payment->refresh()->status);
    }

    public function test_a_legacy_refunding_row_without_a_start_time_can_be_settled_at_once(): void
    {
        $payment = $this->confirmedPaystack();
        Payment::query()->whereKey($payment->id)->update(['status' => PaymentStatus::Refunding->value, 'refund_started_at' => null]);

        app(PaymentRefunder::class)->resolveStuck($payment->refresh(), true, null);

        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
    }

    // An accepted refund is never lost

    public function test_an_accepted_refund_that_cannot_be_recorded_is_logged_reported_and_left_for_staff(): void
    {
        $payment = $this->confirmedPaystack();
        Exceptions::fake();
        Log::spy();
        Event::fake([PaymentRefunded::class]);

        // The row changes under the call (e.g. someone settles it by hand), so complete() refuses.
        Http::fake(['api.paystack.co/refund' => function () use ($payment) {
            Payment::query()->whereKey($payment->id)->update(['status' => PaymentStatus::Confirmed->value]);

            return Http::response(self::QUEUED);
        }]);

        try {
            app(PaymentRefunder::class)->refund($payment, null);
            $this->fail('Expected the unrecorded refund to be reported');
        } catch (BillingException $e) {
            $this->assertSame('The provider accepted this refund, so the money has gone back, but it could not be recorded here. This payment needs attention: check it with the provider, then settle it with “Settle stuck refund”.', $e->getMessage());
        }

        $payment->refresh();
        $this->assertSame(PaymentStatus::Refunding, $payment->status, 'it must not look like an ordinary confirmed payment');
        $this->assertSame('3018284', data_get($payment->meta, 'refund_accepted.provider_refund_id'));
        $this->assertSame(PeriodStatus::Paid, $payment->coveragePeriods()->sole()->status, 'cover stays until a person settles it');
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'refund')
            && $context['payment_id'] === $payment->id
            && $context['gateway_instance_id'] === $payment->gateway_instance_id
            && $context['provider_refund_id'] === '3018284');
        Exceptions::assertReported(BillingException::class);
        Event::assertNotDispatched(PaymentRefunded::class);

        // Staff then settle it as refunded.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($this->window()));
        app(PaymentRefunder::class)->resolveStuck($payment, true, null);
        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
    }

    public function test_an_accepted_refund_on_a_payment_since_settled_as_refunded_keeps_that_state(): void
    {
        $payment = $this->confirmedPaystack();
        Exceptions::fake();

        Http::fake(['api.paystack.co/refund' => function () use ($payment) {
            Payment::query()->whereKey($payment->id)->update(['status' => PaymentStatus::Refunded->value]);

            return Http::response(self::QUEUED);
        }]);

        try {
            app(PaymentRefunder::class)->refund($payment, null);
            $this->fail('Expected the unrecorded refund to be reported');
        } catch (BillingException $e) {
            $this->assertStringContainsString('the money has gone back', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
        $this->assertNotNull(data_get($payment->meta, 'refund_accepted'));
    }

    // Who settled a stuck refund, and when

    public function test_settling_a_stuck_refund_records_who_decided_and_announces_it_after_commit(): void
    {
        $refunded = $this->confirmedPaystack();
        $notRefunded = $this->confirmedPaystack();
        Payment::query()->whereKey([$refunded->id, $notRefunded->id])->update(['status' => PaymentStatus::Refunding->value]);
        $staff = $this->user('Efua');
        Event::fake([StuckRefundResolved::class, PaymentRefunded::class]);

        DB::transaction(function () use ($refunded, $notRefunded, $staff): void {
            app(PaymentRefunder::class)->resolveStuck($refunded->refresh(), true, $staff);
            app(PaymentRefunder::class)->resolveStuck($notRefunded->refresh(), false, $staff);
            Event::assertNotDispatched(StuckRefundResolved::class);
        });

        foreach ([[$refunded, true, 'refunded'], [$notRefunded, false, 'not_refunded']] as [$payment, $decision, $stored]) {
            $payment->refresh();
            $this->assertSame($staff->id, (int) $payment->refund_resolved_by);
            $this->assertTrue(CarbonImmutable::now()->equalTo($payment->refund_resolved_at));
            $this->assertSame($stored, $payment->refund_resolution);
            Event::assertDispatched(StuckRefundResolved::class, fn (StuckRefundResolved $event): bool => $event->payment->is($payment)
                && $event->refunded === $decision
                && $event->by?->getAuthIdentifier() === $staff->id);
        }
        Event::assertDispatchedTimes(StuckRefundResolved::class, 2);
    }

    public function test_no_resolution_event_fires_when_an_outer_transaction_rolls_back(): void
    {
        $payment = $this->confirmedPaystack();
        Payment::query()->whereKey($payment->id)->update(['status' => PaymentStatus::Refunding->value]);
        Event::fake([StuckRefundResolved::class]);

        try {
            DB::transaction(function () use ($payment): void {
                app(PaymentRefunder::class)->resolveStuck($payment->refresh(), false, null);

                throw new RuntimeException('caller rolls back');
            });
        } catch (RuntimeException) {
        }

        Event::assertNotDispatched(StuckRefundResolved::class);
        $this->assertSame(PaymentStatus::Refunding, $payment->refresh()->status);
    }

    // A 2xx answer is never a refusal

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function unclearNotchPayAnswers(): array
    {
        return [
            '202 accepted without a refund object' => [['code' => 202, 'status' => 'Accepted', 'message' => 'Refund is being processed'], 202],
            '200 with a failed refund object' => [['code' => 200, 'refund' => ['id' => 'rf_1', 'status' => 'failed'], 'message' => 'Refund failed'], 200],
            '200 with an empty body' => [[], 200],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('unclearNotchPayAnswers')]
    public function test_a_notchpay_2xx_without_a_clear_success_is_an_unknown_outcome(array $body, int $status): void
    {
        $payment = $this->confirmedNotchPay();
        Http::fake(['api.notchpay.co/refunds' => Http::response($body, $status)]);

        $this->expectUnknownOutcome($payment);
    }

    /**
     * @return array<string, array{0: array<string, mixed>|string, 1: int}>
     */
    public static function unclearPaystackAnswers(): array
    {
        return [
            '200 saying false' => [['status' => false, 'message' => 'Refund could not be queued'], 200],
            '201 without a status' => [['message' => 'Created'], 201],
            '200 unreadable' => ['ok', 200],
            '400 saying true' => [['status' => true, 'message' => 'odd'], 400],
        ];
    }

    /**
     * @param  array<string, mixed>|string  $body
     */
    #[DataProvider('unclearPaystackAnswers')]
    public function test_a_paystack_answer_that_is_neither_a_clear_success_nor_a_clear_refusal_is_unknown(array|string $body, int $status): void
    {
        $payment = $this->confirmedPaystack();
        Http::fake(['api.paystack.co/refund' => Http::response($body, $status)]);

        $this->expectUnknownOutcome($payment);
    }

    public function test_a_notchpay_2xx_with_a_refund_object_succeeds_and_a_4xx_message_refuses(): void
    {
        $payment = $this->confirmedNotchPay();
        Http::fake(['api.notchpay.co/refunds' => Http::sequence()
            ->push(['code' => 422, 'message' => 'Payment cannot be refunded'], 422)
            ->push(['code' => 202, 'message' => 'Accepted', 'refund' => ['id' => 'rf_1', 'status' => 'pending']], 202)]);

        try {
            app(PaymentRefunder::class)->refund($payment, null);
            $this->fail('Expected a refusal');
        } catch (GatewayError $e) {
            $this->assertNotInstanceOf(GatewayOutcomeUnknown::class, $e);
        }
        $this->assertSame(PaymentStatus::Confirmed, $payment->refresh()->status);

        app(PaymentRefunder::class)->refund($payment, null);
        $this->assertSame(PaymentStatus::Refunded, $payment->refresh()->status);
    }

    // A transfer that fails after the response started

    /**
     * @return array<string, array{0: int}>
     */
    public static function brokenTransfers(): array
    {
        return ['502' => [502], '503' => [503], '408' => [408], '422 (refund path: any broken transfer is unknown)' => [422]];
    }

    #[DataProvider('brokenTransfers')]
    public function test_a_refund_transfer_broken_after_the_response_started_is_unknown(int $status): void
    {
        $payment = $this->confirmedPaystack();
        Http::fake(['api.paystack.co/refund' => fn () => throw new RequestException(new Response(new Psr7Response($status, [], '{"status":')))]);

        $this->expectUnknownOutcome($payment);
    }

    public function test_a_verify_transfer_broken_after_a_server_error_started_is_unknown(): void
    {
        Http::fake(['api.paystack.co/*' => fn () => throw new RequestException(new Response(new Psr7Response(504)))]);

        $this->expectException(GatewayOutcomeUnknown::class);
        app(PaystackProvider::class)->verify($this->paystack(), 'BIL-X');
    }

    private function expectUnknownOutcome(Payment $payment): void
    {
        try {
            app(PaymentRefunder::class)->refund($payment, null);
            $this->fail('Expected the unknown outcome to be reported');
        } catch (BillingException $e) {
            $this->assertStringContainsString('may still have gone through', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Refunding, $payment->refresh()->status);
    }
}
