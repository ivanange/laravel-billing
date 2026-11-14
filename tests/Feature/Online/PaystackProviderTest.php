<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature\Online;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Ivanange\Billing\Data\PaymentIntent;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Exceptions\InvalidWebhook;
use Ivanange\Billing\Gateways\Providers\PaystackProvider;
use Ivanange\Billing\Tests\Support\PaystackFakes;
use Ivanange\Billing\Tests\TestCase;
use LogicException;

class PaystackProviderTest extends TestCase
{
    use PaystackFakes;

    private function provider(): PaystackProvider
    {
        return app(PaystackProvider::class);
    }

    private function intent(string $method = PaystackProvider::MOBILE_MONEY): PaymentIntent
    {
        return new PaymentIntent('BIL-TEST', 1500, 'GHS', $method, 'https://app.test/billing/pay/return/BIL-TEST', email: 'ama@example.test');
    }

    public function test_start_initializes_a_transaction_with_the_instance_key_and_returns_the_checkout_url(): void
    {
        Http::fake(['api.paystack.co/transaction/initialize' => $this->paystackInitialized()]);

        $started = $this->provider()->start($this->paystack(secret: 'sk_test_aaa'), $this->intent());

        $this->assertSame('https://checkout.paystack.com/0peioxfhpn', $started->redirectUrl);
        $this->assertSame('BIL-TEST', $started->reference);
        Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.paystack.co/transaction/initialize'
            && $request->header('Authorization') === ['Bearer sk_test_aaa']
            && $request['amount'] === 1500
            && $request['currency'] === 'GHS'
            && $request['email'] === 'ama@example.test'
            && $request['reference'] === 'BIL-TEST'
            && $request['channels'] === ['mobile_money']
            && $request['callback_url'] === 'https://app.test/billing/pay/return/BIL-TEST');
    }

    public function test_a_refused_initialize_is_a_gateway_error_without_the_key(): void
    {
        Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);

        try {
            $this->provider()->start($this->paystack(secret: 'sk_test_wrong'), $this->intent());
            $this->fail('Expected a gateway error.');
        } catch (GatewayError $e) {
            $this->assertSame('Invalid key', $e->providerMessage);
            $this->assertStringNotContainsString('sk_test_wrong', $e->getMessage());
        }
    }

    public function test_a_network_failure_is_a_gateway_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->expectException(GatewayError::class);
        $this->provider()->start($this->paystack(), $this->intent());
    }

    public function test_verify_maps_paystack_statuses(): void
    {
        Http::fake([
            'api.paystack.co/transaction/verify/BIL-OK' => $this->paystackTransaction('BIL-OK'),
            'api.paystack.co/transaction/verify/BIL-NO' => $this->paystackTransaction('BIL-NO', 'failed'),
            'api.paystack.co/transaction/verify/BIL-WAIT' => $this->paystackTransaction('BIL-WAIT', 'abandoned'),
            'api.paystack.co/transaction/verify/BIL-MISSING' => Http::response(['status' => false, 'message' => 'Transaction reference not found'], 400),
        ]);
        $instance = $this->paystack();

        $ok = $this->provider()->verify($instance, 'BIL-OK');
        $this->assertSame(ChargeStatus::Succeeded, $ok->status);
        $this->assertSame(1500, $ok->amount);
        $this->assertSame('GHS', $ok->currency);
        $this->assertSame('4099260516', $ok->externalId);

        $this->assertSame(ChargeStatus::Failed, $this->provider()->verify($instance, 'BIL-NO')->status);
        $this->assertSame(ChargeStatus::Pending, $this->provider()->verify($instance, 'BIL-WAIT')->status);
        $this->assertSame(ChargeStatus::Pending, $this->provider()->verify($instance, 'BIL-MISSING')->status);
    }

    public function test_each_instance_uses_its_own_secret_key(): void
    {
        Http::fake(['api.paystack.co/*' => $this->paystackTransaction('BIL-1')]);
        $ghana = $this->paystack('Paystack Ghana', 'sk_test_ghana');
        $nigeria = $this->paystack('Paystack Nigeria', 'sk_test_nigeria');

        $this->provider()->verify($ghana->refresh(), 'BIL-1');

        Http::assertSent(fn (HttpRequest $request): bool => $request->header('Authorization') === ['Bearer sk_test_ghana']);
        Http::assertNotSent(fn (HttpRequest $request): bool => $request->header('Authorization') === ['Bearer sk_test_nigeria']);
        $this->assertTrue($nigeria->refresh()->is_active);
    }

    public function test_an_instance_of_another_provider_is_refused(): void
    {
        $this->expectException(LogicException::class);
        $this->provider()->verify($this->gateway(), 'BIL-1');
    }

    public function test_webhook_signature_is_a_real_hmac_sha512_of_the_raw_body(): void
    {
        $instance = $this->paystack(secret: 'sk_test_hook');
        [$body, $signature] = $this->paystackWebhook('sk_test_hook', 'BIL-9', 2500);

        $result = $this->provider()->parseWebhook($instance, $this->webhookRequest($body, $signature));

        $this->assertNotNull($result);
        $this->assertSame(ChargeStatus::Succeeded, $result->status);
        $this->assertSame('BIL-9', $result->reference);
        $this->assertSame(2500, $result->amount);
    }

    public function test_webhook_signed_with_another_key_or_tampered_is_rejected(): void
    {
        $instance = $this->paystack(secret: 'sk_test_hook');
        [$body, $wrong] = $this->paystackWebhook('sk_test_other', 'BIL-9');
        [, $right] = $this->paystackWebhook('sk_test_hook', 'BIL-9');

        foreach ([[$body, $wrong], [str_replace('2500', '9', $body.' '), $right], [$body, '']] as [$payload, $signature]) {
            try {
                $this->provider()->parseWebhook($instance, $this->webhookRequest($payload, $signature));
                $this->fail('Expected the webhook to be rejected.');
            } catch (InvalidWebhook $e) {
                $this->assertSame('Invalid webhook signature.', $e->getMessage());
            }
        }
    }

    public function test_other_webhook_events_are_ignored(): void
    {
        $instance = $this->paystack(secret: 'sk_test_hook');
        [$body, $signature] = $this->paystackWebhook('sk_test_hook', 'BIL-9', event: 'transfer.success');

        $this->assertNull($this->provider()->parseWebhook($instance, $this->webhookRequest($body, $signature)));
    }

    private function webhookRequest(string $body, string $signature): Request
    {
        return Request::create('/billing/webhooks/1', 'POST', server: ['HTTP_X_PAYSTACK_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'], content: $body);
    }
}
