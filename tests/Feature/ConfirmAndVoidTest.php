<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Events\PaymentConfirmed;
use Ivanange\Billing\Events\PaymentVoided;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Models\Subscription;
use Ivanange\Billing\Services\PaymentConfirmer;
use Ivanange\Billing\Services\PaymentVoider;
use Ivanange\Billing\Tests\TestCase;

class ConfirmAndVoidTest extends TestCase
{
    public function test_maker_checker_needs_a_second_person(): void
    {
        config(['billing.confirmation.second_person' => true]);
        Event::fake([PaymentConfirmed::class]);
        $maker = $this->user('Maker');
        $checker = $this->user('Checker');
        $member = $this->member();
        $payment = $this->record(['billables' => [$member]], $maker);

        try {
            app(PaymentConfirmer::class)->confirm($payment, $maker);
            $this->fail('The recorder must not confirm their own payment');
        } catch (BillingException $e) {
            $this->assertSame('You recorded this payment, so someone else must confirm it.', $e->getMessage());
        }
        $this->assertFalse($member->isCovered());

        app(PaymentConfirmer::class)->confirm($payment, $checker);

        $payment->refresh();
        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame($checker->id, $payment->confirmed_by);
        $this->assertNotNull($payment->confirmed_at);
        $this->assertTrue($member->isCovered());
        $this->assertSame('2026-04-09', Subscription::query()->sole()->paid_until->toDateString());
        Event::assertDispatchedTimes(PaymentConfirmed::class, 1);

        $this->expectExceptionMessage('This payment is not waiting for confirmation.');
        app(PaymentConfirmer::class)->confirm($payment, $checker);
    }

    public function test_confirming_without_a_user_is_refused_under_maker_checker(): void
    {
        config(['billing.confirmation.second_person' => true]);
        $payment = $this->record();

        $this->expectExceptionMessage('A signed-in user must confirm this payment.');
        app(PaymentConfirmer::class)->confirm($payment, null);
    }

    public function test_voiding_removes_the_cover_but_keeps_every_row(): void
    {
        Event::fake([PaymentVoided::class]);
        $member = $this->member();
        $plan = $this->plan();
        $gateway = $this->gateway();
        $first = $this->record(['billables' => [$member], 'plan' => $plan, 'gateway' => $gateway]);
        $second = $this->record(['billables' => [$member], 'plan' => $plan, 'gateway' => $gateway]);
        $this->assertSame('2026-05-09', $member->paidUntil()->toDateString());
        $admin = $this->user();

        app(PaymentVoider::class)->void($second, '  Entered twice  ', $admin);

        $second->refresh();
        $this->assertSame(PaymentStatus::Void, $second->status);
        $this->assertSame('Entered twice', $second->void_reason);
        $this->assertSame($admin->id, $second->voided_by);
        $this->assertSame('2026-04-09', Subscription::query()->sole()->paid_until->toDateString());
        $this->assertSame(2, Payment::query()->count());
        $this->assertSame([PeriodStatus::Paid, PeriodStatus::Void], CoveragePeriod::query()->orderBy('id')->get()->pluck('status')->all());
        Event::assertDispatched(PaymentVoided::class);

        // The voided dates are free again, and its reference can be reused.
        $this->record(['billables' => [$member], 'plan' => $plan, 'gateway' => $gateway]);
        $this->assertSame('2026-05-09', $member->paidUntil()->toDateString());
        $this->assertSame(PaymentStatus::Confirmed, $first->refresh()->status);
    }

    public function test_void_needs_a_reason_and_happens_once(): void
    {
        $payment = $this->record();

        try {
            app(PaymentVoider::class)->void($payment, ' ', null);
            $this->fail('A reason is required');
        } catch (BillingException $e) {
            $this->assertSame('Give a reason for voiding the payment.', $e->getMessage());
        }

        app(PaymentVoider::class)->void($payment, 'Bounced', null);

        $this->expectExceptionMessage('This payment is already void.');
        app(PaymentVoider::class)->void($payment, 'Again', null);
    }

    public function test_a_rejected_unconfirmed_payment_can_be_voided(): void
    {
        config(['billing.confirmation.second_person' => true]);
        $member = $this->member();
        $payment = $this->record(['billables' => [$member]], $this->user());

        app(PaymentVoider::class)->void($payment, 'Money never arrived', $this->user('Checker'));

        $this->assertSame(PeriodStatus::Void, CoveragePeriod::query()->sole()->status);
        $this->expectException(BillingException::class);
        app(PaymentConfirmer::class)->confirm($payment, $this->user('Other'));
    }
}
