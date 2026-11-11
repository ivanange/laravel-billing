<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature\Online;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Ivanange\Billing\Data\CheckoutRequest;
use Ivanange\Billing\Data\PaymentIntent;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Gateways\Providers\HubtelProvider;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\OnlineCheckout;
use Ivanange\Billing\Services\PaymentRefunder;
use Ivanange\Billing\Tests\Support\OnlineHelpers;
use Ivanange\Billing\Tests\TestCase;

/**
 * Shapes follow https://developers.hubtel.com/docs/business/api_documentation/payment_apis/online_checkout
 */
class HubtelProviderTest extends TestCase
{
    use OnlineHelpers;

    private function initiated(): PromiseInterface
    {
        return Http::response(['responseCode' => '0000', 'status' => 'Success', 'data' => [
            'checkoutUrl' => 'https://pay.hubtel.com/7569a11e', 'checkoutId' => '7569a11e', 'clientReference' => 'BIL-X', 'message' => '',
        ]]);
    }

    private function txnStatus(string $status, float $amount = 15.0): PromiseInterface
    {
        return Http::response(['message' => 'Successful', 'responseCode' => '0000', 'data' => [
            'date' => '2026-03-10T09:00:00Z', 'status' => $status, 'transactionId' => '7569a11e', 'clientReference' => 'BIL-X',
            'currencyCode' => null, 'amount' => $amount, 'charges' => 0.3, 'amountAfterCharges' => $amount - 0.3,
        ]]);
    }

    private function checkout(GatewayInstance $instance): Payment
    {
        Http::fake(['payproxyapi.hubtel.com/*' => $this->initiated()]);
        $price = $this->monthlyPrice();
        app(OnlineCheckout::class)->start(new CheckoutRequest($this->member(), $price->plan, $price, $instance, HubtelProvider::CHECKOUT));

        return Payment::query()->latest('id')->firstOrFail();
    }

    public function test_initiate_uses_basic_auth_cedis_and_this_instances_callback(): void
    {
        $instance = $this->hubtel();
        $payment = $this->checkout($instance);

        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://payproxyapi.hubtel.com/items/initiate'
            && $r->header('Authorization') === ['Basic '.base64_encode('hubtel-id:hubtel-key')]
            && (float) $r['totalAmount'] === 15.0
            && $r['merchantAccountNumber'] === '2017101'
            && $r['clientReference'] === $payment->reference
            && strlen((string) $r['clientReference']) <= 32
            && $r['callbackUrl'] === route('billing.webhooks', $instance->id)
            && $r['returnUrl'] === route('billing.pay.return', $payment->reference)
            && ! preg_match('/[^\pL\pN ]/u', (string) $r['description']));
        $this->assertSame('7569a11e', $payment->external_id);
    }

    public function test_only_cedis_are_accepted(): void
    {
        $this->expectException(BillingException::class);
        app(HubtelProvider::class)->start($this->hubtel(), new PaymentIntent('BIL-X', 100, 'XAF', HubtelProvider::CHECKOUT, 'https://app.test/r'));
    }

    public function test_an_unsigned_callback_is_never_trusted_the_status_api_decides(): void
    {
        $instance = $this->hubtel();
        $payment = $this->checkout($instance);
        // The callback claims success, but the status API says Unpaid.
        Http::fake(['api-txnstatus.hubtel.com/*' => $this->txnStatus('Unpaid')]);

        $this->hubtelCallback($instance, $payment->reference, 'Success')->assertOk();

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === "https://api-txnstatus.hubtel.com/transactions/2017101/status?clientReference={$payment->reference}");
    }

    public function test_a_callback_confirms_when_the_status_api_says_paid(): void
    {
        $instance = $this->hubtel();
        $payment = $this->checkout($instance);
        Http::fake(['api-txnstatus.hubtel.com/*' => $this->txnStatus('Paid')]);

        $this->hubtelCallback($instance, $payment->reference, 'Failed')->assertOk();

        $this->assertSame(PaymentStatus::Confirmed, $payment->refresh()->status);
        $this->assertSame(1500, $payment->coveragePeriods()->sole()->amount);
    }

    public function test_paid_with_a_different_amount_fails(): void
    {
        $instance = $this->hubtel();
        $payment = $this->checkout($instance);
        Http::fake(['api-txnstatus.hubtel.com/*' => $this->txnStatus('Paid', 0.5)]);

        $this->hubtelCallback($instance, $payment->reference)->assertOk();

        $this->assertSame('amount_mismatch', $payment->refresh()->failure_reason);
    }

    public function test_callbacks_for_unknown_references_cause_no_outbound_call_and_garbage_is_rejected(): void
    {
        $instance = $this->hubtel();
        Http::fake();

        $this->hubtelCallback($instance, 'BIL-NOT-OURS')->assertOk();
        $this->call('POST', route('billing.webhooks', $instance->id), content: 'not json')->assertStatus(401);

        Http::assertNothingSent();
    }

    public function test_refunds_are_not_offered_for_hubtel(): void
    {
        $instance = $this->hubtel();
        $payment = $this->checkout($instance);
        $payment->forceFill(['status' => PaymentStatus::Confirmed])->save();

        $this->expectExceptionMessage('This gateway cannot refund payments.');
        app(PaymentRefunder::class)->refund($payment, null);
    }

    private function hubtelCallback(GatewayInstance $instance, ?string $reference, string $status = 'Success'): TestResponse
    {
        return $this->postJson(route('billing.webhooks', $instance->id), [
            'ResponseCode' => '0000', 'Status' => $status,
            'Data' => ['CheckoutId' => '7569a11e', 'ClientReference' => $reference, 'Status' => $status, 'Amount' => 15.0],
        ]);
    }
}
