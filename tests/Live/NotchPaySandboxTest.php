<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Live;

use Ivanange\Billing\Data\PaymentIntent;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Gateways\Providers\NotchPayProvider;
use Ivanange\Billing\Tests\TestCase;

/**
 * Opt-in: real NotchPay API with a test public key (pk_test_…).
 *
 *   NOTCHPAY_TEST_PUBLIC_KEY=pk_test_xxx vendor/bin/phpunit tests/Live
 */
class NotchPaySandboxTest extends TestCase
{
    public function test_initialize_then_verify_against_the_sandbox(): void
    {
        $key = (string) getenv('NOTCHPAY_TEST_PUBLIC_KEY');
        if ($key === '') {
            $this->markTestSkipped('Set NOTCHPAY_TEST_PUBLIC_KEY to run the live NotchPay sandbox test.');
        }
        $this->assertStringContainsString('test', $key, 'Use a NotchPay test key.');

        $instance = $this->notchpay(credentials: ['public_key' => $key]);
        $reference = 'BIL-LIVE-'.bin2hex(random_bytes(6));
        $provider = app(NotchPayProvider::class);

        $started = $provider->start($instance, new PaymentIntent($reference, 100, 'XAF', NotchPayProvider::MTN, 'https://example.test/return/'.$reference, email: 'sandbox@example.test', phone: '+237670000000'));
        $this->assertStringStartsWith('https://', $started->redirectUrl);

        $result = $provider->verify($instance, $reference);
        $this->assertNotSame(ChargeStatus::Succeeded, $result->status);
    }
}
