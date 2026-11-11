<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature\Online;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Events\PaymentConfirmed;
use Ivanange\Billing\Events\PaymentRefunded;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Services\PaymentRefunder;
use Ivanange\Billing\Tests\Support\OnlineHelpers;
use Ivanange\Billing\Tests\TestCase;

class WebhookAndReconcileTest extends TestCase
{
    use OnlineHelpers;

    private function hook(GatewayInstance $instance, string $body, string $signature): TestResponse
    {
        return $this->call('POST', route('billing.webhooks', $instance->id), server: [
            'HTTP_X_PAYSTACK_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json',
        ], content: $body);
    }

    public function test_a_signed_webhook_confirms_the_payment_once(): void
    {
        $instance = $this->paystack(secret: 'sk_test_hook');
        $payment = $this->startCheckout(gateway: $instance);
        [$body, $signature] = $this->paystackWebhook('sk_test_hook', (string) $payment->reference);
        Event::fake([PaymentConfirmed::class]);

        $this->hook($instance, $body, $signature)->assertOk();
        $this->hook($instance, $body, $signature)->assertOk();

        $this->assertSame(PaymentStatus::Confirmed, $payment->refresh()->status);
        $this->assertSame(1, CoveragePeriod::query()->count());
        Event::assertDispatchedTimes(PaymentConfirmed::class, 1);
    }

    public function test_a_bad_signature_is_401_and_changes_nothing_and_logs_no_secret(): void
    {
        $instance = $this->paystack(secret: 'sk_test_hook');
        $payment = $this->startCheckout(gateway: $instance);
        [$body] = $this->paystackWebhook('sk_test_hook', (string) $payment->reference);
        [, $forged] = $this->paystackWebhook('sk_test_attacker', (string) $payment->reference);
        Log::spy();

        $this->hook($instance, $body, $forged)->assertStatus(401);
        $this->hook($instance, $body, '')->assertStatus(401);

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ! str_contains(json_encode($context).$message, 'sk_test') && ! str_contains(json_encode($context), $body));
    }

    public function test_a_webhook_signed_by_another_instance_cannot_confirm_this_instances_payment(): void
    {
        config(['billing.gateways.one_active_per_provider' => false]);
        $ghana = $this->paystack('Ghana', 'sk_test_ghana');
        $nigeria = $this->paystack('Nigeria', 'sk_test_nigeria');
        $payment = $this->startCheckout(gateway: $ghana);
        [$body, $signature] = $this->paystackWebhook('sk_test_nigeria', (string) $payment->reference);

        // Valid for Nigeria's URL, but the payment belongs to Ghana: ignored.
        $this->hook($nigeria, $body, $signature)->assertOk();
        // Sent to Ghana's URL with Nigeria's key: rejected.
        $this->hook($ghana, $body, $signature)->assertStatus(401);

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function test_unknown_instances_and_offline_instances_are_404_and_deleted_instances_still_work(): void
    {
        $this->postJson(route('billing.webhooks', 999), [])->assertNotFound();
        $this->postJson(route('billing.webhooks', $this->gateway()->id), [])->assertNotFound();

        $instance = $this->paystack(secret: 'sk_test_old');
        $payment = $this->startCheckout(gateway: $instance);
        $instance->delete();
        [$body, $signature] = $this->paystackWebhook('sk_test_old', (string) $payment->reference);

        $this->hook($instance, $body, $signature)->assertOk();
        $this->assertSame(PaymentStatus::Confirmed, $payment->refresh()->status);
    }

    public function test_the_webhook_route_has_no_csrf_and_is_rate_limited(): void
    {
        config(['billing.webhooks.rate_limit' => 2]);
        $instance = $this->paystack(secret: 'sk_test_hook');
        [$body, $signature] = $this->paystackWebhook('sk_test_hook', 'BIL-UNKNOWN');

        $this->hook($instance, $body, $signature)->assertOk();
        $this->hook($instance, $body, $signature)->assertOk();
        $this->hook($instance, $body, $signature)->assertStatus(429);
    }

    public function test_reconcile_confirms_late_successes_and_fails_abandoned_checkouts(): void
    {
        $late = $this->startCheckout();
        $abandoned = $this->startCheckout();
        $fresh = $this->startCheckout();
        $late->forceFill(['created_at' => now()->subMinutes(30)])->save();
        $abandoned->forceFill(['created_at' => now()->subDays(2)])->save();
        Http::fake([
            "api.paystack.co/transaction/verify/{$late->reference}" => $this->paystackTransaction((string) $late->reference),
            '*' => $this->paystackTransaction('x', 'abandoned'),
        ]);

        $this->artisan('billing:reconcile-payments')->assertSuccessful();
        $this->artisan('billing:reconcile-payments')->assertSuccessful();

        $this->assertSame(PaymentStatus::Confirmed, $late->refresh()->status);
        $this->assertSame(PaymentStatus::Failed, $abandoned->refresh()->status);
        $this->assertSame('abandoned', $abandoned->failure_reason);
        $this->assertSame(PaymentStatus::Pending, $fresh->refresh()->status, 'too recent to check');
        Http::assertNotSent(fn ($r): bool => str_contains($r->url(), (string) $fresh->reference));
        $this->assertSame(1, CoveragePeriod::query()->count());
    }

    public function test_refund_returns_money_and_voids_the_cover(): void
    {
        $payment = $this->startCheckout();
        Http::fake(["api.paystack.co/transaction/verify/{$payment->reference}" => $this->paystackTransaction((string) $payment->reference)]);
        $this->get(route('billing.pay.return', $payment->reference));
        $member = $payment->refresh()->coveragePeriods()->sole()->billable;
        Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'message' => 'Refund has been queued for processing', 'data' => ['id' => 3018284, 'amount' => 1500, 'currency' => 'GHS', 'status' => 'pending', 'transaction' => ['reference' => $payment->reference]]])]);
        Event::fake([PaymentRefunded::class]);

        app(PaymentRefunder::class)->refund($payment, $this->user());

        $this->assertSame(PaymentStatus::Refunded, $payment->status);
        $this->assertSame(PeriodStatus::Void, $payment->coveragePeriods()->sole()->status);
        $this->assertNull($member->paidUntil());
        Event::assertDispatched(PaymentRefunded::class);
        Http::assertSent(fn ($r): bool => $r->url() === 'https://api.paystack.co/refund' && $r['transaction'] === $payment->reference);

        $this->expectException(BillingException::class);
        app(PaymentRefunder::class)->refund($payment, null);
    }

    public function test_offline_payments_cannot_be_refunded_through_a_provider(): void
    {
        $this->expectExceptionMessage('This gateway cannot refund payments.');
        app(PaymentRefunder::class)->refund($this->record(), null);
    }
}
