<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature\Online;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Ivanange\Billing\Console\ReconcilePaymentsCommand;
use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Data\CheckoutRequest;
use Ivanange\Billing\Data\PaymentIntent;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Events\CoverageExtended;
use Ivanange\Billing\Events\GatewayActivated;
use Ivanange\Billing\Events\PaymentConfirmed;
use Ivanange\Billing\Events\PaymentFailed;
use Ivanange\Billing\Events\PaymentRefunded;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Gateways\GatewayActivation;
use Ivanange\Billing\Gateways\Providers\HubtelProvider;
use Ivanange\Billing\Gateways\Providers\NotchPayProvider;
use Ivanange\Billing\Gateways\Providers\PaystackProvider;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\OnlineCheckout;
use Ivanange\Billing\Services\OnlinePaymentSettler;
use Ivanange\Billing\Services\PayLinks;
use Ivanange\Billing\Services\PaymentRefunder;
use Ivanange\Billing\Tests\Support\OnlineHelpers;
use Ivanange\Billing\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Regression tests for the phases 1-3 audit (online side).
 */
class AuditRegressionTest extends TestCase
{
    use OnlineHelpers;

    private function confirmedPaystack(?GatewayInstance $gateway = null): Payment
    {
        $payment = $this->startCheckout(gateway: $gateway);
        app(OnlinePaymentSettler::class)->settle($payment, new ChargeResult(ChargeStatus::Succeeded, (string) $payment->reference, 1500, 'GHS'));

        return $payment;
    }

    private function notchpayCheckout(): Payment
    {
        $price = $this->monthlyPrice($this->plan(['currency' => 'XAF']), 5000);
        Http::fake(['api.notchpay.co/payments' => Http::response([
            'status' => 'Accepted', 'code' => 201,
            'transaction' => ['id' => 'pay_123', 'reference' => 'trx.abc123', 'trxref' => 'BIL-X', 'status' => 'pending'],
            'authorization_url' => 'https://pay.notchpay.co/pay_123',
        ], 201)]);
        app(OnlineCheckout::class)->start(new CheckoutRequest($this->member(), $price->plan, $price, $this->notchpay(), NotchPayProvider::MTN, phone: '+237670000000'));

        return Payment::query()->latest('id')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function notchpayAnswer(array $transaction, int $http = 200): PromiseInterface
    {
        return Http::response(['code' => 200, 'transaction' => $transaction + ['amount' => 5000, 'currency' => 'XAF', 'status' => 'complete']], $http);
    }

    private function hubtelCheckout(): Payment
    {
        Http::fake(['payproxyapi.hubtel.com/*' => Http::response(['responseCode' => '0000', 'data' => ['checkoutUrl' => 'https://pay.hubtel.com/x', 'checkoutId' => 'chk_1']])]);
        $price = $this->monthlyPrice();
        app(OnlineCheckout::class)->start(new CheckoutRequest($this->member(), $price->plan, $price, $this->hubtel(), HubtelProvider::CHECKOUT));

        return Payment::query()->latest('id')->firstOrFail();
    }

    // 1. Pay-page rate limit

    public function test_pay_pages_from_many_payers_are_not_rate_limited_together(): void
    {
        $this->paystack();
        $price = $this->monthlyPrice();

        foreach (range(1, 12) as $i) {
            $url = app(PayLinks::class)->make($this->member("Payer {$i}"), $price->plan, $price);

            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])->get($url)->assertOk();
        }
    }

    public function test_checkouts_started_from_one_pay_link_are_capped_across_ips(): void
    {
        config(['billing.pay_links.checkouts_per_link_per_hour' => 3]);
        $gateway = $this->paystack();
        $price = $this->monthlyPrice();
        $url = app(PayLinks::class)->make($this->member(), $price->plan, $price);
        $other = app(PayLinks::class)->make($this->member('Kwame'), $price->plan, $price);
        Http::fake(['api.paystack.co/transaction/initialize' => fn ($request) => $this->paystackInitialized((string) $request['reference'])]);
        $choice = ['choice' => "{$gateway->id}:mobile_money", 'email' => 'ama@example.test'];

        foreach (range(1, 3) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.2.{$i}"])->post($url, $choice)->assertRedirect();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.99'])->post($url, $choice)->assertStatus(429);
        $this->assertSame(3, Payment::query()->count(), 'no pending payment or provider checkout once capped');
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.99'])->get($url)->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.99'])->post($other, $choice)->assertRedirect();
    }

    public function test_the_return_route_is_still_capped_per_reference_across_ips(): void
    {
        $payment = $this->startCheckout();
        Http::fake(['*' => $this->paystackTransaction((string) $payment->reference, 'abandoned')]);

        foreach (range(1, 10) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.1.{$i}"])->get(route('billing.pay.return', $payment->reference))->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.99'])->get(route('billing.pay.return', $payment->reference))->assertStatus(429);
    }

    // 2. Refund outcome unknown

    public function test_a_refund_timeout_keeps_the_payment_refunding_and_blocks_a_second_refund(): void
    {
        $payment = $this->confirmedPaystack();
        Http::fake(['api.paystack.co/refund' => fn () => throw new ConnectionException('Operation timed out for Bearer sk_test_ghana')]);
        Log::spy();

        try {
            app(PaymentRefunder::class)->refund($payment, null);
            $this->fail('Expected the unknown outcome to be reported');
        } catch (BillingException $e) {
            $this->assertSame('The provider did not confirm the refund. It may still have gone through: check this payment with the provider before doing anything else. It stays marked “Refund in progress” until then.', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Refunding, $payment->refresh()->status);
        $this->assertSame(PeriodStatus::Paid, $payment->coveragePeriods()->sole()->status, 'cover stays until someone settles it');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'refund')
            && $context['payment_id'] === $payment->id
            && ! str_contains((string) json_encode($context), 'sk_test_ghana'));

        Http::fake();
        $this->expectExceptionMessage('Only confirmed payments can be refunded.');
        try {
            app(PaymentRefunder::class)->refund($payment, null);
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>|string, 1: int}>
     */
    public static function unclearRefundAnswers(): array
    {
        return [
            'html 502' => ['<html>502 Bad Gateway</html>', 502],
            '500 saying false' => [['status' => false, 'message' => 'Internal error'], 500],
            'unreadable 200' => ['ok', 200],
        ];
    }

    /**
     * @param  array<string, mixed>|string  $body
     */
    #[DataProvider('unclearRefundAnswers')]
    public function test_a_server_error_or_unreadable_refund_answer_is_not_a_refusal(array|string $body, int $status): void
    {
        $payment = $this->confirmedPaystack();
        Http::fake(['api.paystack.co/refund' => Http::response($body, $status)]);

        try {
            app(PaymentRefunder::class)->refund($payment, null);
            $this->fail('Expected the unknown outcome to be reported');
        } catch (BillingException $e) {
            $this->assertStringContainsString('may still have gone through', $e->getMessage());
        }

        $this->assertSame(PaymentStatus::Refunding, $payment->refresh()->status);
    }

    public function test_notchpay_refunds_tell_a_refusal_from_an_unknown_outcome(): void
    {
        $payment = $this->notchpayCheckout();
        app(OnlinePaymentSettler::class)->settle($payment, new ChargeResult(ChargeStatus::Succeeded, (string) $payment->reference, 5000, 'XAF'));

        Http::fake(['api.notchpay.co/refunds' => Http::sequence()
            ->push(['code' => 422, 'message' => 'Payment cannot be refunded'], 422)
            // A body claiming success does not override a server error.
            ->push(['code' => 201, 'refund' => ['id' => 'r1', 'status' => 'pending']], 503)]);
        try {
            app(PaymentRefunder::class)->refund($payment, null);
            $this->fail('Expected a refusal');
        } catch (GatewayError $e) {
            $this->assertSame('Payment cannot be refunded', $e->providerMessage);
        }
        $this->assertSame(PaymentStatus::Confirmed, $payment->refresh()->status);

        try {
            app(PaymentRefunder::class)->refund($payment, null);
            $this->fail('Expected the unknown outcome to be reported');
        } catch (BillingException $e) {
            $this->assertStringContainsString('may still have gone through', $e->getMessage());
        }
        $this->assertSame(PaymentStatus::Refunding, $payment->refresh()->status);
    }

    public function test_staff_settle_a_stuck_refund_either_way(): void
    {
        $refunded = $this->confirmedPaystack();
        $notRefunded = $this->confirmedPaystack();
        Payment::query()->whereKey([$refunded->id, $notRefunded->id])->update(['status' => PaymentStatus::Refunding->value]);
        $member = $notRefunded->coveragePeriods()->sole()->billable;
        Event::fake([PaymentRefunded::class]);
        Http::fake();

        $staff = $this->user();
        app(PaymentRefunder::class)->resolveStuck($refunded->refresh(), true, $staff);
        app(PaymentRefunder::class)->resolveStuck($notRefunded->refresh(), false, $staff);

        $this->assertSame(PaymentStatus::Refunded, $refunded->status);
        $this->assertSame($staff->id, (int) $refunded->refunded_by);
        $this->assertSame(PeriodStatus::Void, $refunded->coveragePeriods()->sole()->status);
        $this->assertSame(PaymentStatus::Confirmed, $notRefunded->status);
        $this->assertSame(PeriodStatus::Paid, $notRefunded->coveragePeriods()->sole()->status);
        $this->assertTrue($member->paidUntil()->equalTo($notRefunded->coveragePeriods()->sole()->ends_on));
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
        Http::assertNothingSent();

        $this->expectExceptionMessage('Only payments marked “Refund in progress” can be settled this way.');
        app(PaymentRefunder::class)->resolveStuck($notRefunded, true, null);
    }

    // 3. Verify checks the provider's own reference

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function otherHubtelReferences(): array
    {
        return ['another payment' => ['BIL-SOMEONE-ELSE'], 'no reference' => [null]];
    }

    #[DataProvider('otherHubtelReferences')]
    public function test_a_hubtel_status_answer_for_another_reference_does_not_confirm(?string $theirs): void
    {
        $payment = $this->hubtelCheckout();
        Http::fake(['api-txnstatus.hubtel.com/*' => Http::response(['responseCode' => '0000', 'data' => array_filter([
            'status' => 'Paid', 'amount' => 15.0, 'clientReference' => $theirs, 'transactionId' => 't1',
        ])])]);

        $result = app(HubtelProvider::class)->verify($payment->gatewayInstance, (string) $payment->reference);
        app(OnlinePaymentSettler::class)->settle($payment, $result);

        $this->assertSame($theirs ?? '', $result->reference, 'Hubtel\'s own reference, never the one we asked about');
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    /**
     * "OURS" stands for the payment's own reference.
     *
     * @return array<string, array{0: bool, 1: array<string, string>, 2: bool}>
     */
    public static function notchpayVerifyAnswers(): array
    {
        return [
            // Looked up by NotchPay's reference (stored at checkout): it may answer
            // with that reference, or with ours as reference/trxref.
            'another trx' => [true, ['reference' => 'trx.other'], false],
            'another of ours' => [true, ['reference' => 'BIL-SOMEONE-ELSE'], false],
            'another trx and trxref' => [true, ['reference' => 'trx.other', 'trxref' => 'BIL-SOMEONE-ELSE'], false],
            'no reference' => [true, [], false],
            'the stored trx' => [true, ['reference' => 'trx.abc123'], true],
            'ours' => [true, ['reference' => 'OURS'], true],
            'ours as trxref' => [true, ['reference' => 'trx.other', 'trxref' => 'OURS'], true],
            // Without a stored NotchPay reference the lookup uses ours, and only
            // our reference in the answer counts.
            'unstored: a trx alone' => [false, ['reference' => 'trx.abc123'], false],
            'unstored: ours as trxref' => [false, ['reference' => 'trx.abc123', 'trxref' => 'OURS'], true],
        ];
    }

    /**
     * @param  array<string, string>  $fields
     */
    #[DataProvider('notchpayVerifyAnswers')]
    public function test_notchpay_verify_must_name_the_payment_it_was_asked_about(bool $stored, array $fields, bool $matches): void
    {
        $payment = $this->notchpayCheckout();
        if (! $stored) {
            $payment->forceFill(['external_id' => null])->save();
        }
        $fields = array_map(fn (string $value): string => $value === 'OURS' ? (string) $payment->reference : $value, $fields);
        $lookup = $stored ? 'trx.abc123' : (string) $payment->reference;
        Http::fake(["api.notchpay.co/payments/{$lookup}" => $this->notchpayAnswer($fields)]);

        $result = app(NotchPayProvider::class)->verify($payment->gatewayInstance, (string) $payment->reference);

        $this->assertSame($matches, app(OnlinePaymentSettler::class)->identifies($payment, $result));
        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    // 4. HTTP status is never taken from the body

    public function test_a_notchpay_server_error_never_verifies_or_redirects_whatever_the_body_says(): void
    {
        $instance = $this->notchpay();
        Http::fake(['api.notchpay.co/payments/BIL-X' => Http::response(['code' => 200, 'transaction' => ['reference' => 'BIL-X', 'status' => 'complete', 'amount' => 5000, 'currency' => 'XAF']], 500)]);

        try {
            app(NotchPayProvider::class)->verify($instance, 'BIL-X');
            $this->fail('Expected a gateway error');
        } catch (GatewayError $e) {
            $this->assertStringContainsString('HTTP 500', (string) $e->providerMessage);
        }

        Http::fake(['api.notchpay.co/payments' => Http::response(['code' => 201, 'authorization_url' => 'https://pay.notchpay.co/x'], 500)]);
        $this->expectException(GatewayError::class);
        app(NotchPayProvider::class)->start($instance, new PaymentIntent('BIL-Y', 5000, 'XAF', NotchPayProvider::MTN, 'https://app.test/r', phone: '+237670000000'));
    }

    public function test_a_hubtel_body_cannot_override_the_http_status(): void
    {
        Http::fake(['api-txnstatus.hubtel.com/*' => Http::response(['httpStatus' => 200, 'responseCode' => '0000', 'data' => ['status' => 'Paid', 'clientReference' => 'BIL-X', 'amount' => 15.0]], 403)]);

        $this->expectException(GatewayError::class);
        app(HubtelProvider::class)->verify($this->hubtel(), 'BIL-X');
    }

    // 5. Decimal amounts are never truncated

    /**
     * @return array<string, array{0: string, 1: PaymentStatus}>
     */
    public static function paystackDecimalAmounts(): array
    {
        return ['fractional' => ['1500.9', PaymentStatus::Failed], 'whole decimal' => ['1500.00', PaymentStatus::Confirmed]];
    }

    #[DataProvider('paystackDecimalAmounts')]
    public function test_a_fractional_provider_amount_is_a_mismatch_but_a_whole_decimal_is_accepted(string $amount, PaymentStatus $expected): void
    {
        $payment = $this->startCheckout();
        Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => [
            'status' => 'success', 'reference' => $payment->reference, 'amount' => $amount, 'currency' => 'GHS', 'id' => 1,
        ]])]);

        $this->get(route('billing.pay.return', $payment->reference))->assertOk();

        $this->assertSame($expected, $payment->refresh()->status);
    }

    public function test_a_hubtel_amount_below_one_pesewa_is_not_rounded_into_a_match(): void
    {
        // 15.004 used to round to 1500 pesewas and confirm.
        $hubtel = $this->hubtelCheckout();
        Http::fake(['api-txnstatus.hubtel.com/*' => Http::response(['responseCode' => '0000', 'data' => [
            'status' => 'Paid', 'amount' => 15.004, 'clientReference' => $hubtel->reference, 'transactionId' => 't1',
        ]])]);
        $this->get(route('billing.pay.return', $hubtel->reference))->assertOk();
        $this->assertSame('amount_mismatch', $hubtel->refresh()->failure_reason, 'sub-pesewa amounts are not rounded into a match');
    }

    // 6. Reconcile logs errors and marks unverifiable payments

    public function test_reconcile_logs_each_verify_error_and_marks_stale_unverifiable_payments(): void
    {
        $payment = $this->hubtelCheckout();
        $payment->forceFill(['created_at' => now()->subDays(2)])->save();
        Http::fake(['api-txnstatus.hubtel.com/*' => Http::response('<html>Forbidden</html>', 403)]);
        Log::spy();

        $this->artisan('billing:reconcile-payments')->assertSuccessful();

        $this->assertSame(PaymentStatus::Failed, $payment->refresh()->status);
        $this->assertSame('unverifiable', $payment->failure_reason);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'reconcile')
            && $context['payment_id'] === $payment->id
            && $context['gateway_instance_id'] === $payment->gateway_instance_id
            && str_contains((string) $context['provider_message'], 'whitelisted')
            && ! str_contains((string) json_encode($context), 'hubtel-key'));
    }

    public function test_reconcile_treats_an_answer_about_another_payment_as_unverifiable(): void
    {
        $payment = $this->startCheckout();
        $payment->forceFill(['created_at' => now()->subDays(2)])->save();
        Http::fake(['*' => $this->paystackTransaction('BIL-SOMEONE-ELSE')]);
        Log::spy();

        $this->artisan('billing:reconcile-payments')->assertSuccessful();

        $this->assertSame('unverifiable', $payment->refresh()->failure_reason);
        $this->assertSame(0, CoveragePeriod::query()->count());
        // Not the provider's "Approved": that was about another payment.
        $this->assertSame(ReconcilePaymentsCommand::NOT_THIS_PAYMENT, data_get($payment->meta, 'failure.provider_message'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $context['provider_message'] === ReconcilePaymentsCommand::NOT_THIS_PAYMENT);
    }

    public function test_an_unverifiable_payment_is_still_confirmed_by_a_later_webhook_or_return(): void
    {
        $byWebhook = $this->startCheckout(gateway: $instance = $this->paystack(secret: 'sk_test_hook'));
        $byReturn = $this->startCheckout();
        Payment::query()->update(['created_at' => now()->subDays(2)]);
        $providerDown = true;
        Http::fake(['*' => function () use (&$providerDown, $byReturn) {
            return $providerDown ? throw new ConnectionException('timeout') : $this->paystackTransaction((string) $byReturn->reference);
        }]);
        $this->artisan('billing:reconcile-payments')->assertSuccessful();
        $this->assertSame('unverifiable', $byWebhook->refresh()->failure_reason);
        $this->assertSame('unverifiable', $byReturn->refresh()->failure_reason);

        [$body, $signature] = $this->paystackWebhook('sk_test_hook', (string) $byWebhook->reference);
        $this->call('POST', route('billing.webhooks', $instance->id), server: ['HTTP_X_PAYSTACK_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], content: $body)->assertOk();

        $providerDown = false;
        $this->get(route('billing.pay.return', $byReturn->reference))->assertOk()->assertSee('Payment received. Thank you!');

        $this->assertSame(PaymentStatus::Confirmed, $byWebhook->refresh()->status);
        $this->assertSame(PaymentStatus::Confirmed, $byReturn->refresh()->status);
        $this->assertSame(2, CoveragePeriod::query()->count());
    }

    // 7. Events only after the outermost commit

    public function test_no_event_fires_when_an_outer_transaction_rolls_back(): void
    {
        $confirming = $this->startCheckout();
        $failing = $this->startCheckout();
        $refunding = $this->confirmedPaystack();
        Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'message' => 'Queued', 'data' => ['id' => 1]])]);
        Event::fake([PaymentConfirmed::class, CoverageExtended::class, PaymentFailed::class, PaymentRefunded::class]);

        try {
            DB::transaction(function () use ($confirming, $failing, $refunding): void {
                app(OnlinePaymentSettler::class)->settle($confirming, new ChargeResult(ChargeStatus::Succeeded, (string) $confirming->reference, 1500, 'GHS'));
                app(OnlinePaymentSettler::class)->settle($failing, new ChargeResult(ChargeStatus::Failed, (string) $failing->reference));
                app(OnlinePaymentSettler::class)->abandon(Payment::query()->findOrFail($this->startCheckout()->id));
                app(PaymentRefunder::class)->refund($refunding, null);

                throw new RuntimeException('caller rolls back');
            });
        } catch (RuntimeException) {
        }

        Event::assertNotDispatched(PaymentConfirmed::class);
        Event::assertNotDispatched(CoverageExtended::class);
        Event::assertNotDispatched(PaymentFailed::class);
        Event::assertNotDispatched(PaymentRefunded::class);
        $this->assertSame(PaymentStatus::Pending, $confirming->refresh()->status);
    }

    public function test_a_failed_checkout_start_or_an_activation_fires_nothing_when_the_caller_rolls_back(): void
    {
        $gateway = $this->paystack();
        $other = $this->paystack('Paystack Two', active: false);
        $price = $this->monthlyPrice();
        Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);
        Event::fake([PaymentFailed::class, GatewayActivated::class]);

        try {
            DB::transaction(function () use ($gateway, $other, $price): void {
                try {
                    app(OnlineCheckout::class)->start(new CheckoutRequest($this->member(), $price->plan, $price, $gateway, PaystackProvider::MOBILE_MONEY, email: 'ama@example.test'));
                } catch (GatewayError) {
                }
                app(GatewayActivation::class)->activate($other);

                throw new RuntimeException('caller rolls back');
            });
        } catch (RuntimeException) {
        }

        Event::assertNotDispatched(PaymentFailed::class);
        Event::assertNotDispatched(GatewayActivated::class);
    }

    public function test_events_fire_once_after_an_outer_transaction_commits(): void
    {
        $payment = $this->startCheckout();
        Event::fake([PaymentConfirmed::class, CoverageExtended::class]);

        DB::transaction(function () use ($payment): void {
            app(OnlinePaymentSettler::class)->settle($payment, new ChargeResult(ChargeStatus::Succeeded, (string) $payment->reference, 1500, 'GHS'));
            Event::assertNotDispatched(PaymentConfirmed::class);
        });

        Event::assertDispatchedTimes(PaymentConfirmed::class, 1);
        Event::assertDispatchedTimes(CoverageExtended::class, 1);
    }
}
