<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature\Online;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Data\CheckoutRequest;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Enums\FailureReason;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Events\CoverageExtended;
use Ivanange\Billing\Events\PaymentConfirmed;
use Ivanange\Billing\Events\PaymentFailed;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Exceptions\GatewayError;
use Ivanange\Billing\Gateways\Providers\PaystackProvider;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Models\Subscription;
use Ivanange\Billing\Services\OnlineCheckout;
use Ivanange\Billing\Services\OnlinePaymentSettler;
use Ivanange\Billing\Tests\Support\OnlineHelpers;
use Ivanange\Billing\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class OnlineLifecycleTest extends TestCase
{
    use OnlineHelpers;

    private function succeeded(Payment $payment, ?int $amount = null, string $currency = 'GHS'): ChargeResult
    {
        return new ChargeResult(ChargeStatus::Succeeded, (string) $payment->reference, $amount ?? $payment->amount, $currency, '4099260516');
    }

    private function settle(Payment $payment, ChargeResult $result): Payment
    {
        return app(OnlinePaymentSettler::class)->settle($payment, $result);
    }

    public function test_starting_a_checkout_stores_a_pending_payment_without_cover(): void
    {
        $payment = $this->startCheckout();

        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(1500, $payment->amount);
        $this->assertSame('GHS', $payment->currency);
        $this->assertMatchesRegularExpression('/^BIL-[0-9A-Z]{26}$/', (string) $payment->reference);
        $this->assertTrue($payment->isOnline());
        $this->assertSame(0, CoveragePeriod::query()->count(), 'an abandoned checkout must not reserve dates');
    }

    public function test_checkout_rules(): void
    {
        $price = $this->monthlyPrice();
        $request = fn (array $o = []) => new CheckoutRequest(...$o + [
            'billable' => $this->member(), 'plan' => $price->plan, 'price' => $price,
            'gateway' => $this->paystack(), 'method' => PaystackProvider::MOBILE_MONEY, 'email' => 'ama@example.test',
        ]);
        $cases = [
            'offline gateway' => [['gateway' => $this->gateway(['name' => 'Cash'])], 'This gateway does not take online payments.'],
            'missing email' => [['email' => null], 'Enter a valid email address.'],
            'method off' => [['method' => PaystackProvider::BANK_TRANSFER], 'This payment method is not enabled for the chosen gateway.'],
            'foreign price' => [['price' => $this->monthlyPrice($this->plan(['name' => 'Other']))], 'This price does not belong to the plan.'],
        ];

        foreach ($cases as $name => [$overrides, $message]) {
            try {
                app(OnlineCheckout::class)->start($request($overrides));
                $this->fail("{$name}: expected a rule violation");
            } catch (BillingException $e) {
                $this->assertSame($message, $e->getMessage(), $name);
            }
        }
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_a_provider_refusal_marks_the_payment_failed(): void
    {
        Http::fake(['api.paystack.co/*' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);
        Event::fake([PaymentFailed::class]);
        $price = $this->monthlyPrice();

        try {
            app(OnlineCheckout::class)->start(new CheckoutRequest($this->member(), $price->plan, $price, $this->paystack(), 'card', 'ama@example.test'));
            $this->fail('Expected a gateway error');
        } catch (GatewayError) {
        }

        $this->assertSame(PaymentStatus::Failed, Payment::query()->sole()->status);
        $this->assertSame('start_failed', Payment::query()->sole()->failure_reason);
        Event::assertDispatched(PaymentFailed::class);
    }

    public function test_a_verified_success_confirms_and_creates_cover_from_paid_until(): void
    {
        $member = $this->member();
        $existing = $this->record(['billables' => [$member], 'plan' => $plan = $this->plan()]);
        $paidUntil = $existing->coveragePeriods()->first()->ends_on;
        $payment = $this->startCheckout($member, $this->paystack(), $this->monthlyPrice($plan));
        Event::fake([PaymentConfirmed::class, CoverageExtended::class]);

        $this->settle($payment, $this->succeeded($payment));

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $period = $payment->coveragePeriods()->sole();
        $this->assertSame(PeriodStatus::Paid, $period->status);
        $this->assertTrue($period->starts_on->equalTo($paidUntil->addDay()));
        $this->assertTrue($member->paidUntil($plan)->equalTo($period->ends_on));
        $this->assertSame('BIL-TEST', $payment->external_id, 'the id stored at checkout is kept');
        Event::assertDispatchedTimes(PaymentConfirmed::class, 1);
        Event::assertDispatchedTimes(CoverageExtended::class, 1);
    }

    public function test_duplicate_results_and_callback_plus_webhook_confirm_once(): void
    {
        $payment = $this->startCheckout();
        Event::fake([PaymentConfirmed::class]);

        // The return URL and the webhook each load their own copy of the row.
        $fromCallback = Payment::query()->findOrFail($payment->id);
        $fromWebhook = Payment::query()->findOrFail($payment->id);
        $this->settle($fromCallback, $this->succeeded($payment));
        $this->settle($fromWebhook, $this->succeeded($payment));
        $this->settle($fromWebhook, $this->succeeded($payment));

        $this->assertSame(1, CoveragePeriod::query()->count());
        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(PaymentStatus::Confirmed, $fromWebhook->status);
        Event::assertDispatchedTimes(PaymentConfirmed::class, 1);
    }

    public function test_amount_or_currency_mismatch_fails_without_cover(): void
    {
        foreach ([[1, 'GHS'], [1500, 'NGN']] as [$amount, $currency]) {
            $payment = $this->startCheckout();

            $this->settle($payment, $this->succeeded($payment, $amount, $currency));

            $this->assertSame(PaymentStatus::Failed, $payment->status);
            $this->assertSame('amount_mismatch', $payment->failure_reason);
            $this->assertSame(['amount' => $amount, 'currency' => $currency], $payment->meta['failure']['reported']);
        }
        $this->assertSame(0, CoveragePeriod::query()->count());
    }

    /**
     * @return array<string, array{0: ?int, 1: string}>
     */
    public static function mismatchedAnswers(): array
    {
        return ['one pesewa short' => [1499, 'GHS'], 'one pesewa over' => [1501, 'GHS'], 'other currency' => [1500, 'NGN'], 'no amount' => [null, 'GHS']];
    }

    #[DataProvider('mismatchedAnswers')]
    public function test_a_verified_answer_with_another_amount_or_currency_fails_without_cover(?int $amount, string $currency): void
    {
        $payment = $this->startCheckout();
        Http::fake(["api.paystack.co/transaction/verify/{$payment->reference}" => Http::response(['status' => true, 'data' => array_filter([
            'status' => 'success', 'reference' => $payment->reference, 'amount' => $amount, 'currency' => $currency, 'id' => 1,
        ], fn (mixed $value): bool => $value !== null)])]);

        $this->get(route('billing.pay.return', $payment->reference))->assertOk()->assertSee('did not go through');

        $this->assertSame(PaymentStatus::Failed, $payment->refresh()->status);
        $this->assertSame(FailureReason::AmountMismatch, $payment->failureReason());
        $this->assertSame(['amount' => $amount, 'currency' => $currency], $payment->meta['failure']['reported']);
        $this->assertSame(0, CoveragePeriod::query()->count());
    }

    public function test_declines_fail_and_a_later_verified_success_still_confirms(): void
    {
        $payment = $this->startCheckout();

        $this->settle($payment, new ChargeResult(ChargeStatus::Failed, (string) $payment->reference, message: 'Declined'));
        $this->assertSame(PaymentStatus::Failed, $payment->status);
        $this->assertSame('declined', $payment->failure_reason);

        $this->settle($payment, new ChargeResult(ChargeStatus::Pending, (string) $payment->reference));
        $this->assertSame(PaymentStatus::Failed, $payment->status);

        $this->settle($payment, $this->succeeded($payment));
        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertNull($payment->failure_reason);
    }

    public function test_a_void_or_confirmed_payment_is_never_changed_by_the_provider(): void
    {
        $payment = $this->startCheckout();
        $payment->forceFill(['status' => PaymentStatus::Void])->save();

        $this->settle($payment, $this->succeeded($payment));
        $this->assertSame(PaymentStatus::Void, $payment->refresh()->status);

        $other = $this->startCheckout();
        $this->settle($other, $this->succeeded($other));
        $this->settle($other, new ChargeResult(ChargeStatus::Failed, (string) $other->reference));
        $this->assertSame(PaymentStatus::Confirmed, $other->refresh()->status);
    }

    public function test_a_result_for_another_reference_is_ignored(): void
    {
        $payment = $this->startCheckout();

        $this->settle($payment, new ChargeResult(ChargeStatus::Succeeded, 'BIL-SOMEONE-ELSE', 1500, 'GHS'));

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }
}
