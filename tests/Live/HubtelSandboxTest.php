<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Live;

use Ivanange\Billing\Data\PaymentIntent;
use Ivanange\Billing\Gateways\Providers\HubtelProvider;
use Ivanange\Billing\Tests\TestCase;

/**
 * Opt-in: real Hubtel API. Hubtel has no public sandbox: this creates a
 * real (unpaid) checkout on the merchant account. The status check also
 * needs this machine's IP whitelisted by Hubtel.
 *
 *   HUBTEL_TEST_API_ID=… HUBTEL_TEST_API_KEY=… HUBTEL_TEST_ACCOUNT=… vendor/bin/phpunit tests/Live
 */
class HubtelSandboxTest extends TestCase
{
    public function test_initiate_a_checkout(): void
    {
        [$id, $key, $account] = [getenv('HUBTEL_TEST_API_ID'), getenv('HUBTEL_TEST_API_KEY'), getenv('HUBTEL_TEST_ACCOUNT')];
        if (! $id || ! $key || ! $account) {
            $this->markTestSkipped('Set HUBTEL_TEST_API_ID, HUBTEL_TEST_API_KEY and HUBTEL_TEST_ACCOUNT to run the live Hubtel test.');
        }

        $instance = $this->hubtel(credentials: ['api_id' => (string) $id, 'api_key' => (string) $key]);
        $instance->forceFill(['settings' => ['merchant_account' => (string) $account]])->save();
        $reference = 'BIL-LIVE-'.bin2hex(random_bytes(6));

        $started = app(HubtelProvider::class)->start($instance, new PaymentIntent($reference, 100, 'GHS', HubtelProvider::CHECKOUT, 'https://example.test/return/'.$reference));

        $this->assertStringStartsWith('https://pay.hubtel.com/', $started->redirectUrl);
    }
}
