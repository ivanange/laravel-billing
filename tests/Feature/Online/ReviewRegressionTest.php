<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature\Online;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Events\PaymentFailed;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\OnlinePaymentSettler;
use Ivanange\Billing\Services\PaymentRefunder;
use Ivanange\Billing\Tests\Fixtures\Member;
use Ivanange\Billing\Tests\Support\OnlineHelpers;
use Ivanange\Billing\Tests\TestCase;

/**
 * Regression tests for the phase 2 code and security review findings.
 */
class ReviewRegressionTest extends TestCase
{
    use OnlineHelpers;

    private function success(Payment $payment, int $amount = 1500, string $currency = 'GHS'): ChargeResult
    {
        return new ChargeResult(ChargeStatus::Succeeded, (string) $payment->reference, $amount, $currency);
    }

    private function confirmed(): Payment
    {
        $payment = $this->startCheckout();
        app(OnlinePaymentSettler::class)->settle($payment, $this->success($payment));

        return $payment;
    }

    public function test_repeated_mismatch_results_fire_payment_failed_once(): void
    {
        $payment = $this->startCheckout();
        Event::fake([PaymentFailed::class]);

        foreach (range(1, 3) as $ignored) {
            app(OnlinePaymentSettler::class)->settle(Payment::query()->findOrFail($payment->id), $this->success($payment, 1));
        }

        Event::assertDispatchedTimes(PaymentFailed::class, 1);
    }

    public function test_cover_uses_the_term_snapshotted_at_checkout(): void
    {
        $price = $this->monthlyPrice();
        $payment = $this->startCheckout(price: $price);
        $price->update(['duration_unit' => 'year', 'duration_count' => 3]);

        app(OnlinePaymentSettler::class)->settle($payment, $this->success($payment));

        $period = $payment->coveragePeriods()->sole();
        $this->assertSame('2026-04-09', $period->ends_on->toDateString(), 'one month, as at checkout');
    }

    public function test_deleted_price_still_gives_the_paid_cover_and_a_deleted_billable_is_flagged(): void
    {
        $price = $this->monthlyPrice();
        $payment = $this->startCheckout(price: $price);
        $price->delete();
        app(OnlinePaymentSettler::class)->settle($payment, $this->success($payment));
        $this->assertSame(1, $payment->coveragePeriods()->count());

        $member = $this->member('Gone');
        $orphan = $this->startCheckout($member);
        Member::query()->whereKey($member->id)->delete();
        app(OnlinePaymentSettler::class)->settle($orphan, $this->success($orphan));
        $this->assertSame(PaymentStatus::Confirmed, $orphan->status);
        $this->assertTrue($orphan->meta['unallocated']);
        $this->assertSame(0, $orphan->coveragePeriods()->count());
    }

    public function test_a_late_success_clears_the_failure_details(): void
    {
        $payment = $this->startCheckout();
        app(OnlinePaymentSettler::class)->settle($payment, new ChargeResult(ChargeStatus::Failed, (string) $payment->reference, message: 'Declined'));
        app(OnlinePaymentSettler::class)->settle($payment, $this->success($payment));

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertArrayNotHasKey('failure', $payment->meta);
    }

    public function test_a_refused_refund_leaves_the_payment_confirmed_and_refunds_cannot_run_twice(): void
    {
        $payment = $this->confirmed();
        Http::fake(['api.paystack.co/refund' => Http::response(['status' => false, 'message' => 'Transaction has been fully reversed'], 400)]);

        try {
            app(PaymentRefunder::class)->refund($payment, null);
            $this->fail('Expected a gateway error');
        } catch (GatewayError $e) {
            $this->assertSame('Transaction has been fully reversed', $e->providerMessage);
        }
        $this->assertSame(PaymentStatus::Confirmed, $payment->refresh()->status);

        // A refund already in flight blocks a second one before any provider call.
        $payment->forceFill(['status' => PaymentStatus::Refunding])->save();
        Http::fake();
        try {
            app(PaymentRefunder::class)->refund($payment, null);
            $this->fail('Expected the second refund to be refused');
        } catch (BillingException $e) {
            $this->assertSame('Only confirmed payments can be refunded.', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_reconcile_keeps_going_when_one_payment_breaks(): void
    {
        $broken = $this->startCheckout();
        $fine = $this->startCheckout();
        Payment::query()->update(['created_at' => now()->subHour()]);
        GatewayInstance::query()->whereKey($broken->gateway_instance_id)->update(['provider' => 'unregistered']);
        $fine->forceFill(['gateway_instance_id' => $this->paystack('Other', 'sk_test_other')->id])->save();
        Http::fake(['*' => $this->paystackTransaction((string) $fine->reference)]);

        $this->artisan('billing:reconcile-payments')->assertSuccessful();

        $this->assertSame(PaymentStatus::Pending, $broken->refresh()->status);
        $this->assertSame(PaymentStatus::Confirmed, $fine->refresh()->status);
    }

    public function test_gateway_errors_redact_keys_and_tokens(): void
    {
        $error = GatewayError::from('401 for Bearer sk_live_abc123 and sk_test_xyz and Basic QUJDOkRFRg==');

        $this->assertSame('401 for [redacted] and [redacted] and [redacted]', $error->providerMessage);
        $this->assertNull($error->getPrevious());
    }

    public function test_pay_pages_are_not_cached_and_send_no_referrer(): void
    {
        $payment = $this->startCheckout();
        Http::fake(['*' => $this->paystackTransaction((string) $payment->reference, 'abandoned')]);

        $this->get(route('billing.pay.return', $payment->reference))
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_return_page_stops_reverifying_old_or_mismatched_failures(): void
    {
        $payment = $this->startCheckout();
        app(OnlinePaymentSettler::class)->settle($payment, $this->success($payment, 1));
        Http::fake();

        $this->get(route('billing.pay.return', $payment->reference))->assertOk()->assertSee('did not go through');

        Http::assertNothingSent();
        $this->assertSame(0, CoveragePeriod::query()->count());
    }
}
