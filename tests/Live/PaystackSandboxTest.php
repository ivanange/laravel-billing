<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Live;

use Ivanange\Billing\Data\PaymentIntent;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Gateways\Providers\PaystackProvider;
use Ivanange\Billing\Tests\TestCase;

/**
 * Opt-in: talks to the real Paystack API in test mode. Runs only when
 * PAYSTACK_TEST_SECRET (an sk_test_ key) is set; skipped otherwise, e.g. in CI.
 *
 *   PAYSTACK_TEST_SECRET=sk_test_xxx vendor/bin/phpunit tests/Live
 */
class PaystackSandboxTest extends TestCase
{
    private function secret(): string
    {
        $secret = (string) getenv('PAYSTACK_TEST_SECRET');

        if ($secret === '') {
            $this->markTestSkipped('Set PAYSTACK_TEST_SECRET to run the live Paystack sandbox test.');
        }

        if (! str_starts_with($secret, 'sk_test_')) {
            $this->fail('PAYSTACK_TEST_SECRET must be a test key (sk_test_...).');
        }

        return $secret;
    }

    public function test_initialize_then_verify_against_the_sandbox(): void
    {
        $instance = $this->paystack(secret: $this->secret());
        $reference = 'BIL-LIVE-'.bin2hex(random_bytes(6));
        $provider = app(PaystackProvider::class);

        $started = $provider->start($instance, new PaymentIntent(
            reference: $reference,
            amount: 100,
            currency: (string) (getenv('PAYSTACK_TEST_CURRENCY') ?: 'GHS'),
            method: PaystackProvider::CARD,
            returnUrl: 'https://example.test/billing/pay/return/'.$reference,
            email: 'sandbox@example.test',
        ));

        $this->assertStringStartsWith('https://checkout.paystack.com/', $started->redirectUrl);

        // Nobody paid: Paystack reports the transaction as not (yet) successful.
        $result = $provider->verify($instance, $reference);
        $this->assertSame($reference, $result->reference);
        $this->assertNotSame(ChargeStatus::Succeeded, $result->status);
    }

    public function test_a_wrong_key_is_a_gateway_error(): void
    {
        $this->secret();
        $instance = $this->paystack(secret: 'sk_test_definitely_not_a_key');

        $this->expectException(GatewayError::class);
        app(PaystackProvider::class)->start($instance, new PaymentIntent('BIL-LIVE-BAD', 100, 'GHS', 'card', 'https://example.test/r', email: 'sandbox@example.test'));
    }
}
