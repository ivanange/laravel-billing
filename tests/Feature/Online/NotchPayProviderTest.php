<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature\Online;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Ivanange\Billing\Data\CheckoutRequest;
use Ivanange\Billing\Data\PaymentIntent;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Gateways\Providers\NotchPayProvider;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\OnlineCheckout;
use Ivanange\Billing\Services\PaymentRefunder;
use Ivanange\Billing\Tests\Support\OnlineHelpers;
use Ivanange\Billing\Tests\TestCase;

/**
 * Response bodies follow https://developer.notchpay.co/api-reference/payments.md
 */
class NotchPayProviderTest extends TestCase
{
    use OnlineHelpers;

    private function provider(): NotchPayProvider
    {
        return app(NotchPayProvider::class);
    }

    private function initialized(): PromiseInterface
    {
        return Http::response([
            'status' => 'Accepted', 'message' => 'Payment initialized', 'code' => 201,
            'transaction' => ['id' => 'pay_123', 'reference' => 'trx.abc123', 'trxref' => 'BIL-X', 'amount' => 5000, 'currency' => 'XAF', 'status' => 'pending'],
            'authorization_url' => 'https://pay.notchpay.co/pay_123',
        ], 201);
    }

    private function transaction(string $status, int $amount = 5000, string $currency = 'XAF'): PromiseInterface
    {
        return Http::response([
            'status' => 'Accepted', 'message' => 'Transaction retrieved', 'code' => 202,
            'transaction' => ['id' => 'pay_123', 'reference' => 'BIL-X', 'amount' => $amount, 'currency' => $currency, 'status' => $status],
        ], 202);
    }

    public function test_start_sends_whole_francs_with_the_public_key_and_locks_the_channel(): void
    {
        Http::fake(['api.notchpay.co/payments' => $this->initialized()]);

        $started = $this->provider()->start($this->notchpay(), new PaymentIntent('BIL-X', 5000, 'XAF', NotchPayProvider::ORANGE, 'https://app.test/return/BIL-X', phone: '+237690000000'));

        $this->assertSame('https://pay.notchpay.co/pay_123', $started->redirectUrl);
        $this->assertSame('trx.abc123', $started->externalId);
        Http::assertSent(fn (HttpRequest $r): bool => $r->method() === 'POST'
            && $r->url() === 'https://api.notchpay.co/payments'
            && $r->header('Authorization') === ['pk_test_cm']
            && ! $r->hasHeader('X-Grant')
            && $r['amount'] === 5000
            && $r['currency'] === 'XAF'
            && $r['reference'] === 'BIL-X'
            && $r['phone'] === '+237690000000'
            && $r['locked_channel'] === 'cm.orange'
            && $r['callback'] === 'https://app.test/return/BIL-X');
    }

    public function test_an_invalid_key_is_a_gateway_error(): void
    {
        Http::fake(['api.notchpay.co/*' => Http::response(['status' => 'Unauthorized', 'message' => 'Invalid API key', 'code' => 401], 401)]);

        $this->expectException(GatewayError::class);
        $this->provider()->start($this->notchpay(), new PaymentIntent('BIL-X', 5000, 'XAF', NotchPayProvider::MTN, 'https://app.test/r', phone: '+237670000000'));
    }

    public function test_verify_maps_statuses(): void
    {
        $instance = $this->notchpay();
        $cases = ['complete' => ChargeStatus::Succeeded, 'pending' => ChargeStatus::Pending, 'processing' => ChargeStatus::Pending, 'failed' => ChargeStatus::Failed, 'canceled' => ChargeStatus::Failed, 'expired' => ChargeStatus::Failed];
        $sequence = Http::sequence();
        foreach (array_keys($cases) as $status) {
            $sequence->pushResponse($this->transaction($status));
        }
        Http::fake(['api.notchpay.co/payments/BIL-X' => $sequence]);

        foreach ($cases as $status => $expected) {

            $result = $this->provider()->verify($instance, 'BIL-X');

            $this->assertSame($expected, $result->status, $status);
            $this->assertSame(5000, $result->amount);
            $this->assertSame('XAF', $result->currency);
        }
    }

    public function test_an_xaf_checkout_confirms_end_to_end_with_zero_decimal_amounts(): void
    {
        $price = $this->monthlyPrice($this->plan(['currency' => 'XAF']), 5000);
        Http::fake(['api.notchpay.co/payments' => $this->initialized()]);
        app(OnlineCheckout::class)->start(new CheckoutRequest($this->member(), $price->plan, $price, $this->notchpay(), NotchPayProvider::MTN, phone: '+237670000000'));
        $payment = Payment::query()->sole();
        $this->assertSame(5000, $payment->amount);
        $this->assertSame('trx.abc123', $payment->external_id);

        // Verified with NotchPay's own reference stored at checkout.
        Http::fake(['api.notchpay.co/payments/trx.abc123' => $this->transaction('complete')]);
        $this->get(route('billing.pay.return', $payment->reference))->assertOk();

        $this->assertSame(PaymentStatus::Confirmed, $payment->refresh()->status);
        $this->assertSame(5000, $payment->coveragePeriods()->sole()->amount);
    }

    public function test_verify_errors_are_errors_not_pending(): void
    {
        $instance = $this->notchpay();
        foreach ([Http::response(['message' => 'Invalid API key'], 401), Http::response(['message' => 'Payment not found', 'code' => 404], 404), Http::response('<html>502</html>', 502)] as $answer) {
            Http::fake(['api.notchpay.co/*' => $answer]);
            try {
                $this->provider()->verify($instance, 'BIL-X');
                $this->fail('Expected a gateway error');
            } catch (GatewayError $e) {
                $this->assertStringContainsString('NotchPay verify failed', (string) $e->providerMessage);
            }
            $this->app->forgetInstance(Factory::class);
            Http::clearResolvedInstances();
        }
    }

    public function test_webhook_failure_events_fail_the_payment(): void
    {
        $instance = $this->notchpay();
        $price = $this->monthlyPrice($this->plan(['currency' => 'XAF']), 5000);
        Http::fake(['api.notchpay.co/payments' => $this->initialized()]);
        app(OnlineCheckout::class)->start(new CheckoutRequest($this->member(), $price->plan, $price, $instance, NotchPayProvider::MTN, phone: '+237670000000'));
        $payment = Payment::query()->sole();

        $body = (string) json_encode(['type' => 'payment.expired', 'data' => ['reference' => 'trx.abc123', 'trxref' => $payment->reference, 'amount' => 5000, 'currency' => 'XAF']]);
        $this->hook($instance, $body, hash_hmac('sha256', $body, 'hsk_test_cm'))->assertOk();

        $this->assertSame(PaymentStatus::Failed, $payment->refresh()->status);
    }

    public function test_phone_is_required_for_notchpay(): void
    {
        $price = $this->monthlyPrice($this->plan(['currency' => 'XAF']), 5000);

        $this->expectExceptionMessage('Enter a phone number.');
        app(OnlineCheckout::class)->start(new CheckoutRequest($this->member(), $price->plan, $price, $this->notchpay(), NotchPayProvider::MTN));
    }

    public function test_signed_webhooks_confirm_by_our_reference_or_notchpays(): void
    {
        $instance = $this->notchpay();
        $price = $this->monthlyPrice($this->plan(['currency' => 'XAF']), 5000);
        Http::fake(['api.notchpay.co/payments' => $this->initialized()]);
        app(OnlineCheckout::class)->start(new CheckoutRequest($this->member(), $price->plan, $price, $instance, NotchPayProvider::MTN, phone: '+237670000000'));
        $payment = Payment::query()->sole();

        // NotchPay's own reference only (trx.…), as some payload versions send.
        $body = (string) json_encode(['id' => 'evt_1', 'type' => 'payment.complete', 'data' => ['id' => 'pay_123', 'reference' => 'trx.abc123', 'amount' => 5000, 'currency' => 'XAF', 'status' => 'complete']]);
        $this->hook($instance, $body, hash_hmac('sha256', $body, 'hsk_test_cm'))->assertOk();

        $this->assertSame(PaymentStatus::Confirmed, $payment->refresh()->status);
    }

    public function test_webhooks_with_a_wrong_signature_are_rejected_and_other_events_ignored(): void
    {
        $instance = $this->notchpay();
        $body = (string) json_encode(['type' => 'payment.complete', 'data' => ['reference' => 'BIL-X', 'amount' => 5000, 'currency' => 'XAF']]);

        $this->hook($instance, $body, hash_hmac('sha256', $body, 'pk_test_cm'))->assertStatus(401);
        $this->hook($instance, $body, '')->assertStatus(401);

        $created = (string) json_encode(['type' => 'payment.created', 'data' => ['reference' => 'BIL-X']]);
        $this->assertNull($this->provider()->parseWebhook($instance, Request::create('/', 'POST', server: ['HTTP_X_NOTCH_SIGNATURE' => hash_hmac('sha256', $created, 'hsk_test_cm')], content: $created)));
    }

    public function test_a_reference_match_wins_over_another_payments_external_id(): void
    {
        $instance = $this->notchpay();
        $price = $this->monthlyPrice($this->plan(['currency' => 'XAF']), 5000);
        Http::fake(['api.notchpay.co/payments' => $this->initialized()]);
        app(OnlineCheckout::class)->start(new CheckoutRequest($this->member('A'), $price->plan, $price, $instance, NotchPayProvider::MTN, phone: '+237670000000'));
        $first = Payment::query()->sole();
        $second = Payment::query()->create($first->only(['payer_type', 'payer_id', 'gateway_instance_id', 'method', 'amount', 'currency', 'paid_on', 'meta']) + ['status' => PaymentStatus::Pending, 'reference' => 'trx.abc123x']);
        $first->forceFill(['external_id' => 'trx.abc123x'])->save();

        $body = (string) json_encode(['type' => 'payment.complete', 'data' => ['reference' => 'trx.abc123x', 'amount' => 5000, 'currency' => 'XAF']]);
        $this->hook($instance, $body, hash_hmac('sha256', $body, 'hsk_test_cm'))->assertOk();

        $this->assertSame(PaymentStatus::Confirmed, $second->refresh()->status);
        $this->assertSame(PaymentStatus::Pending, $first->refresh()->status);
    }

    public function test_refund_sends_the_private_key_as_grant(): void
    {
        $price = $this->monthlyPrice($this->plan(['currency' => 'XAF']), 5000);
        Http::fake(['api.notchpay.co/payments' => $this->initialized()]);
        app(OnlineCheckout::class)->start(new CheckoutRequest($this->member(), $price->plan, $price, $this->notchpay(), NotchPayProvider::MTN, phone: '+237670000000'));
        $payment = Payment::query()->sole();
        Http::fake([
            'api.notchpay.co/payments/trx.abc123' => $this->transaction('complete'),
            'api.notchpay.co/refunds' => Http::response(['code' => 201, 'message' => 'Refund created', 'refund' => ['id' => 'ref_1', 'status' => 'pending']], 201),
        ]);
        $this->get(route('billing.pay.return', $payment->reference));

        app(PaymentRefunder::class)->refund($payment->refresh(), null);

        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://api.notchpay.co/refunds'
            && $r->header('X-Grant') === ['sk_test_cm'] && $r['payment'] === 'trx.abc123');
    }

    private function hook(GatewayInstance $instance, string $body, string $signature): TestResponse
    {
        return $this->call('POST', route('billing.webhooks', $instance->id), server: ['HTTP_X_NOTCH_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], content: $body);
    }
}
