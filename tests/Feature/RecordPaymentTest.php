<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Events\CoverageExtended;
use Ivanange\Billing\Events\PaymentConfirmed;
use Ivanange\Billing\Events\PaymentRecorded;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Models\BillingCalendar;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\Subscription;
use Ivanange\Billing\Periods\CalendarTerm;
use Ivanange\Billing\Periods\RangeTerm;
use Ivanange\Billing\Tests\Fixtures\Company;
use Ivanange\Billing\Tests\Fixtures\Member;
use Ivanange\Billing\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RecordPaymentTest extends TestCase
{
    public function test_cash_for_three_months_gives_cover_until_the_day_before_the_same_date(): void
    {
        Event::fake([PaymentRecorded::class, PaymentConfirmed::class, CoverageExtended::class]);
        $member = $this->member();
        $recorder = $this->user();

        $payment = $this->record(['billables' => [$member], 'term' => $this->months(3), 'amountPerBillable' => 1500, 'reference' => ' R-1 '], $recorder);

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame(1500, $payment->amount);
        $this->assertSame('R-1', $payment->reference);
        $this->assertSame($recorder->id, $payment->recorded_by);
        $this->assertSame('2026-06-09', $member->paidUntil()->toDateString());
        $this->assertSame('2026-06-09', Subscription::query()->sole()->paid_until->toDateString());
        $this->assertTrue($member->isCovered());
        $this->assertTrue($member->isCovered(CarbonImmutable::parse('2026-06-09')));
        $this->assertFalse($member->isCovered(CarbonImmutable::parse('2026-06-10')));
        $this->assertSame('3 months', CoveragePeriod::query()->sole()->label);
        Event::assertDispatched(PaymentRecorded::class);
        Event::assertDispatched(PaymentConfirmed::class);
        Event::assertDispatched(CoverageExtended::class);
    }

    public function test_month_end_start_does_not_overflow(): void
    {
        $member = $this->member();
        $this->record(['billables' => [$member], 'startsOn' => CarbonImmutable::parse('2026-01-31')]);

        $this->assertSame('2026-02-27', $member->paidUntil()->toDateString());
    }

    public function test_custom_range_and_calendar_periods(): void
    {
        $member = $this->member();
        $this->record(['billables' => [$member], 'term' => new RangeTerm(CarbonImmutable::parse('2026-04-01'), CarbonImmutable::parse('2026-10-31'), 'Season 2026')]);

        $this->assertSame('Season 2026', CoveragePeriod::query()->sole()->label);
        $this->assertSame('2026-10-31', $member->paidUntil()->toDateString());

        $calendar = BillingCalendar::query()->create(['name' => 'School year']);
        $term2 = $calendar->periods()->create(['label' => 'Term 2', 'starts_on' => '2027-01-05', 'ends_on' => '2027-04-02']);
        $term3 = $calendar->periods()->create(['label' => 'Term 3', 'starts_on' => '2027-04-20', 'ends_on' => '2027-07-20']);

        $student = $this->member('Esi');
        $payment = $this->record(['billables' => [$student], 'term' => CalendarTerm::fromIds([$term3->id, $term2->id]), 'amountPerBillable' => 1001]);

        $periods = $student->coveragePeriods()->orderBy('starts_on')->get();
        $this->assertSame(['Term 2', 'Term 3'], $periods->pluck('label')->all());
        $this->assertSame([500, 501], $payment->allocations()->orderBy('id')->pluck('amount')->all());
        $this->assertFalse($student->isCovered(CarbonImmutable::parse('2027-04-10')), 'holiday between terms is not covered');
        $this->assertTrue($student->isCovered(CarbonImmutable::parse('2027-05-01')));
    }

    public function test_next_payment_continues_after_existing_cover(): void
    {
        $member = $this->member();
        $plan = $this->plan();
        $gateway = $this->gateway();
        $this->record(['billables' => [$member], 'plan' => $plan, 'gateway' => $gateway]);
        $this->record(['billables' => [$member], 'plan' => $plan, 'gateway' => $gateway]);

        $this->assertSame(['2026-03-10', '2026-04-10'], $member->coveragePeriods()->orderBy('starts_on')->get()->map(fn ($p) => $p->starts_on->toDateString())->all());
    }

    public function test_after_a_lapse_cover_starts_today_but_grace_keeps_it_continuous(): void
    {
        $gateway = $this->gateway();
        $strict = $this->plan();
        $lenient = $this->plan(['name' => 'Lenient', 'grace_days' => 7]);
        $a = $this->member('A');
        $b = $this->member('B');

        foreach ([[$a, $strict], [$b, $lenient]] as [$member, $plan]) {
            $this->record(['billables' => [$member], 'plan' => $plan, 'gateway' => $gateway, 'startsOn' => CarbonImmutable::parse('2026-02-01')]);
        }

        // Cover ended 28 Feb; today is 10 Mar.
        $this->record(['billables' => [$a], 'plan' => $strict, 'gateway' => $gateway]);
        $this->assertSame('2026-03-10', $a->coveragePeriods()->latest('id')->first()->starts_on->toDateString());

        // 7 grace days run to 7 Mar, still lapsed by the 10th...
        $this->record(['billables' => [$b], 'plan' => $lenient, 'gateway' => $gateway]);
        $this->assertSame('2026-03-10', $b->coveragePeriods()->latest('id')->first()->starts_on->toDateString());

        // ...but inside grace the next period starts right after the old one.
        CarbonImmutable::setTestNow('2026-04-12'); // cover ended 9 Apr, grace runs to 16 Apr
        $this->record(['billables' => [$b], 'plan' => $lenient, 'gateway' => $gateway]);
        $this->assertSame('2026-04-10', $b->coveragePeriods()->latest('id')->first()->starts_on->toDateString());
    }

    public function test_overlapping_cover_is_rejected_unless_allowed(): void
    {
        $member = $this->member();
        $plan = $this->plan();
        $gateway = $this->gateway();
        $this->record(['billables' => [$member], 'plan' => $plan, 'gateway' => $gateway]);

        try {
            $this->record(['billables' => [$member], 'plan' => $plan, 'gateway' => $gateway, 'startsOn' => CarbonImmutable::parse('2026-03-20')]);
            $this->fail('Overlap should be rejected');
        } catch (BillingException $e) {
            $this->assertStringContainsString('Ama already has cover from 2026-03-10 to 2026-04-09', $e->getMessage());
        }

        $this->record(['billables' => [$member], 'plan' => $plan, 'gateway' => $gateway, 'startsOn' => CarbonImmutable::parse('2026-03-20'), 'allowOverlap' => true]);
        $this->assertSame(2, $member->coveragePeriods()->count());
    }

    public function test_one_payment_cannot_cover_the_same_days_twice_unless_allowed(): void
    {
        $calendar = BillingCalendar::query()->create(['name' => 'Badly drawn']);
        $spring = $calendar->periods()->create(['label' => 'Spring', 'starts_on' => '2026-04-01', 'ends_on' => '2026-06-30']);
        $summer = $calendar->periods()->create(['label' => 'Summer', 'starts_on' => '2026-06-01', 'ends_on' => '2026-08-31']);
        $member = $this->member();
        $plan = $this->plan();
        $gateway = $this->gateway();
        $term = CalendarTerm::fromIds([$spring->id, $summer->id]);

        try {
            $this->record(['billables' => [$member], 'plan' => $plan, 'gateway' => $gateway, 'term' => $term]);
            $this->fail('Overlapping spans in one payment should be rejected');
        } catch (BillingException $e) {
            $this->assertSame('This payment covers 2026-06-01 to 2026-06-30 twice for Ama. Pick periods that do not share days, or allow the overlap.', $e->getMessage());
        }
        $this->assertSame(0, CoveragePeriod::query()->count());

        $this->record(['billables' => [$member], 'plan' => $plan, 'gateway' => $gateway, 'term' => $term, 'allowOverlap' => true]);
        $this->assertSame(2, $member->coveragePeriods()->count());
    }

    public function test_a_subscription_is_paid_by_its_billable_unless_a_payer_is_given(): void
    {
        $plan = $this->plan();
        $gateway = $this->gateway();
        [$a, $b, $c] = [$this->member('A'), $this->member('B'), $this->member('C')];
        $company = Company::query()->create(['name' => 'Co-op']);

        $group = $this->record(['billables' => [$a, $b], 'plan' => $plan, 'gateway' => $gateway]);
        $this->assertNull($group->payer_id, 'a group payment without a payer names nobody');
        foreach ([$a, $b] as $member) {
            $subscription = $member->subscriptions()->sole();
            $this->assertTrue($subscription->payer->is($member), "{$member->name} pays for their own subscription");
        }

        $paid = $this->record(['billables' => [$c], 'plan' => $plan, 'gateway' => $gateway, 'payer' => $company]);
        $this->assertTrue($paid->payer->is($company));
        $this->assertTrue($c->subscriptions()->sole()->payer->is($company));
    }

    public function test_one_payment_can_cover_a_whole_group(): void
    {
        $members = collect(range(1, 40))->map(fn (int $i): Member => $this->member("Farmer {$i}"))->all();

        $payment = $this->record(['billables' => $members, 'amountPerBillable' => 500]);

        $this->assertSame(20000, $payment->amount);
        $this->assertNull($payment->payer_id, 'group payments have no single payer unless given');
        $this->assertSame(40, $payment->allocations()->count());
        $this->assertSame(40, Member::query()->covered()->count());
    }

    public function test_covered_scope_respects_grace_days_at_the_edges(): void
    {
        $plan = $this->plan(['grace_days' => 2]);
        $member = $this->member();
        $this->record(['billables' => [$member], 'plan' => $plan]);  // 10 Mar – 9 Apr, grace to 11 Apr

        $this->assertSame(0, Member::query()->covered(CarbonImmutable::parse('2026-03-09'))->count());
        $this->assertSame(1, Member::query()->covered(CarbonImmutable::parse('2026-03-10'))->count());
        $this->assertSame(1, Member::query()->covered(CarbonImmutable::parse('2026-04-11'))->count());
        $this->assertSame(0, Member::query()->covered(CarbonImmutable::parse('2026-04-12'))->count());
        $this->assertSame(1, Member::query()->notCovered(CarbonImmutable::parse('2026-04-12'))->count());

        $plan->update(['grace_days' => 5]);
        $this->assertSame(1, Member::query()->covered(CarbonImmutable::parse('2026-04-14'))->count(), 'grace change applies to existing periods');
        $this->assertSame(0, Member::query()->covered(CarbonImmutable::parse('2026-04-14'), $this->plan(['name' => 'Other']))->count());
    }

    public function test_complimentary_cover_needs_a_zero_amount(): void
    {
        $member = $this->member();
        $payment = $this->record(['billables' => [$member], 'method' => 'complimentary', 'amountPerBillable' => 0]);

        $this->assertSame(0, $payment->amount);
        $this->assertTrue($member->isCovered());

        $this->expectExceptionMessage('Free cover must have an amount of 0.');
        $this->record(['method' => 'complimentary', 'amountPerBillable' => 100]);
    }

    /**
     * @return iterable<string, array{0: callable(self): array<string, mixed>, 1: string}>
     */
    public static function invalidDrafts(): iterable
    {
        yield 'no billables' => [fn (self $t) => ['billables' => []], 'Choose who the payment is for.'];
        yield 'zero amount' => [fn (self $t) => ['amountPerBillable' => 0], 'Enter the amount received.'];
        yield 'negative amount' => [fn (self $t) => ['amountPerBillable' => -1], 'The amount cannot be negative.'];
        yield 'method not enabled' => [fn (self $t) => ['method' => 'cheque'], 'This payment method is not enabled'];
        yield 'currency' => [fn (self $t) => ['gateway' => $t->gateway(['currencies' => ['XAF']])], 'does not accept GHS'];
        yield 'inactive gateway' => [fn (self $t) => ['gateway' => $t->gateway([], active: false)], 'This payment gateway is not active.'];
        yield 'inactive plan' => [fn (self $t) => ['plan' => $t->plan(['is_active' => false])], 'This plan is not active.'];
        yield 'reference required' => [fn (self $t) => ['gateway' => $t->gateway(['settings' => ['require_reference' => true]]), 'reference' => '  '], 'Enter the receipt or transaction number.'];
        yield 'proof required' => [fn (self $t) => ['gateway' => $t->gateway(['settings' => ['require_proof' => true]])], 'Upload a proof of payment.'];
        yield 'billable type' => [fn (self $t) => ['plan' => $t->plan(['billable_type' => 'companies'])], 'This plan cannot be used for Ama.'];
    }

    #[DataProvider('invalidDrafts')]
    public function test_invalid_payments_are_rejected_without_side_effects(callable $overrides, string $message): void
    {
        try {
            $this->record($overrides($this));
            $this->fail('Expected a BillingException');
        } catch (BillingException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame(0, CoveragePeriod::query()->count());
        $this->assertSame(0, Subscription::query()->count());
    }

    public function test_the_same_reference_cannot_be_recorded_twice_on_one_gateway(): void
    {
        $gateway = $this->gateway();
        $this->record(['gateway' => $gateway, 'reference' => 'MOMO-77']);

        $this->expectExceptionMessage('A payment with reference MOMO-77 was already recorded.');
        $this->record(['gateway' => $gateway, 'reference' => 'MOMO-77']);
    }

    public function test_a_billable_cannot_appear_twice_in_one_payment(): void
    {
        $member = $this->member();

        $this->expectExceptionMessage('Ama is listed twice.');
        $this->record(['billables' => [$member, $member]]);
    }

    public function test_periods_are_pending_until_confirmed(): void
    {
        config(['billing.confirmation.second_person' => true]);
        $member = $this->member();

        $payment = $this->record(['billables' => [$member]], $this->user());

        $this->assertSame(PaymentStatus::Recorded, $payment->status);
        $this->assertSame(PeriodStatus::Pending, CoveragePeriod::query()->sole()->status);
        $this->assertFalse($member->isCovered());
        $this->assertNull(Subscription::query()->sole()->paid_until);
    }
}
