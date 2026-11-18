<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature\Filament;

use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Ivanange\Billing\Enums\FailureReason;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Events\PaymentRefunded;
use Ivanange\Billing\Events\StuckRefundResolved;
use Ivanange\Billing\Filament\Actions\ResolveStuckRefundAction;
use Ivanange\Billing\Filament\Resources\Payments\Pages\ListPayments;
use Ivanange\Billing\Filament\Resources\Payments\Pages\ViewPayment;
use Ivanange\Billing\Filament\Resources\Payments\PaymentResource;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\CoverageLedger;
use Ivanange\Billing\Services\OnlinePaymentSettler;
use Ivanange\Billing\Services\PaymentRefunder;
use Ivanange\Billing\Tests\FilamentTestCase;
use Ivanange\Billing\Tests\Support\OnlineHelpers;
use Livewire\Livewire;

/**
 * Settling a refund the provider never answered clearly, and finding failed
 * payments that may really have been paid.
 */
class StuckRefundActionTest extends FilamentTestCase
{
    use OnlineHelpers;

    public function test_the_action_shows_only_on_refunding_payments_to_users_who_may_refund(): void
    {
        $stuck = $this->stuck();
        $confirmed = $this->record();

        Livewire::test(ListPayments::class)->assertActionHidden(TestAction::make('resolveStuckRefund')->table($stuck));
        Livewire::test(ViewPayment::class, ['record' => $stuck->id])->assertActionHidden('resolveStuckRefund');

        Gate::define('billing.refund', fn (): bool => true);

        Livewire::test(ListPayments::class)
            ->assertActionVisible(TestAction::make('resolveStuckRefund')->table($stuck))
            ->assertActionHidden(TestAction::make('resolveStuckRefund')->table($confirmed));
        Livewire::test(ViewPayment::class, ['record' => $stuck->id])->assertActionVisible('resolveStuckRefund');
        Livewire::test(ViewPayment::class, ['record' => $confirmed->id])->assertActionHidden('resolveStuckRefund');
    }

    public function test_went_through_completes_the_refund_without_calling_the_provider(): void
    {
        $stuck = $this->stuck();
        $member = $stuck->coveragePeriods()->sole()->billable;
        Gate::define('billing.refund', fn (): bool => true);
        Event::fake([PaymentRefunded::class]);
        Http::fake();

        Livewire::test(ViewPayment::class, ['record' => $stuck->id])
            ->callAction('resolveStuckRefund', ['outcome' => 'refunded'])
            ->assertHasNoFormErrors()
            ->assertNotified(__('billing::billing.ui.payment.stuck_refunded'));

        $stuck->refresh();
        $this->assertSame(PaymentStatus::Refunded, $stuck->status);
        $this->assertSame(auth()->id(), (int) $stuck->refunded_by);
        $this->assertSame(PeriodStatus::Void, $stuck->coveragePeriods()->sole()->status);
        $this->assertFalse($member->isCovered());
        Event::assertDispatchedTimes(PaymentRefunded::class, 1);
        Http::assertNothingSent();
    }

    public function test_did_not_go_through_puts_it_back_to_confirmed_with_its_cover(): void
    {
        $stuck = $this->stuck();
        $member = $stuck->coveragePeriods()->sole()->billable;
        Gate::define('billing.refund', fn (): bool => true);
        Event::fake([PaymentRefunded::class]);
        Http::fake();

        Livewire::test(ListPayments::class)
            ->callAction(TestAction::make('resolveStuckRefund')->table($stuck), ['outcome' => 'not_refunded'])
            ->assertHasNoFormErrors()
            ->assertNotified(__('billing::billing.ui.payment.stuck_not_refunded'));

        $stuck->refresh();
        $this->assertSame(PaymentStatus::Confirmed, $stuck->status);
        $this->assertNull($stuck->refunded_at);
        $this->assertSame(PeriodStatus::Paid, $stuck->coveragePeriods()->sole()->status);
        $this->assertTrue($member->isCovered());
        Event::assertNotDispatched(PaymentRefunded::class);
        Http::assertNothingSent();
    }

    public function test_the_person_must_say_what_happened_at_the_provider(): void
    {
        $stuck = $this->stuck();
        Gate::define('billing.refund', fn (): bool => true);

        Livewire::test(ViewPayment::class, ['record' => $stuck->id])
            ->callAction('resolveStuckRefund')
            ->assertHasFormErrors(['outcome' => 'required']);

        Livewire::test(ViewPayment::class, ['record' => $stuck->id])
            ->callAction('resolveStuckRefund', ['outcome' => 'maybe'])
            ->assertHasFormErrors(['outcome']);

        $this->assertSame(PaymentStatus::Refunding, $stuck->refresh()->status);
    }

    public function test_the_handler_checks_the_refund_ability_itself(): void
    {
        $stuck = $this->stuck();
        $allowed = true;
        Gate::define('billing.refund', function () use (&$allowed): bool {
            return $allowed;
        });

        $page = Livewire::test(ViewPayment::class, ['record' => $stuck->id])
            ->mountAction('resolveStuckRefund')
            ->setActionData(['outcome' => 'refunded']);

        $allowed = false;
        $page->callMountedAction();
        $this->assertSame(PaymentStatus::Refunding, $stuck->refresh()->status);

        // The handler itself refuses, not only the hidden button.
        try {
            ResolveStuckRefundAction::make()->record($stuck)->call(['data' => ['outcome' => 'refunded']]);
            $this->fail('The handler ran without the billing.refund ability.');
        } catch (AuthorizationException) {
        }
        $this->assertSame(PaymentStatus::Refunding, $stuck->refresh()->status);
    }

    public function test_settling_a_payment_someone_else_already_settled_is_refused(): void
    {
        $stuck = $this->stuck();
        Gate::define('billing.refund', fn (): bool => true);

        // Another admin settles it between the click and the row lock.
        $this->app->bind(PaymentRefunder::class, fn ($app) => new class($app->make(ConnectionInterface::class), $app->make(CoverageLedger::class), $app->make(Config::class)) extends PaymentRefunder
        {
            public function resolveStuck(Payment $payment, bool $refunded, ?Authenticatable $by): Payment
            {
                Payment::query()->whereKey($payment->getKey())->update(['status' => PaymentStatus::Confirmed->value]);

                return parent::resolveStuck($payment, $refunded, $by);
            }
        });

        Livewire::test(ViewPayment::class, ['record' => $stuck->id])
            ->callAction('resolveStuckRefund', ['outcome' => 'refunded'])
            ->assertNotified(__('billing::billing.errors.not_refunding'));

        $this->assertSame(PaymentStatus::Confirmed, $stuck->refresh()->status);
        $this->assertSame(PeriodStatus::Paid, $stuck->coveragePeriods()->sole()->status);
    }

    public function test_a_refund_whose_call_may_still_be_running_cannot_be_settled_yet(): void
    {
        $stuck = $this->stuck();
        Payment::query()->whereKey($stuck->id)->update(['refund_started_at' => CarbonImmutable::now()->subSeconds(30)]);
        Gate::define('billing.refund', fn (): bool => true);
        Event::fake([StuckRefundResolved::class]);

        Livewire::test(ViewPayment::class, ['record' => $stuck->id])
            ->callAction('resolveStuckRefund', ['outcome' => 'not_refunded'])
            ->assertNotified(__('billing::billing.errors.refund_may_be_in_progress', ['seconds' => 60]));

        $stuck->refresh();
        $this->assertSame(PaymentStatus::Refunding, $stuck->status);
        $this->assertNull($stuck->refund_resolved_at);
        Event::assertNotDispatched(StuckRefundResolved::class);
    }

    public function test_settling_records_who_decided(): void
    {
        $stuck = $this->stuck();
        Gate::define('billing.refund', fn (): bool => true);

        Livewire::test(ViewPayment::class, ['record' => $stuck->id])
            ->callAction('resolveStuckRefund', ['outcome' => 'not_refunded'])
            ->assertHasNoFormErrors();

        $stuck->refresh();
        $this->assertSame(auth()->id(), (int) $stuck->refund_resolved_by);
        $this->assertSame('not_refunded', $stuck->refund_resolution);
        $this->assertNotNull($stuck->refund_resolved_at);
        Livewire::test(ViewPayment::class, ['record' => $stuck->id])
            ->assertSee(__('billing::billing.ui.payment.refund_resolved_by'))
            ->assertSee((string) auth()->user()?->name);
    }

    public function test_the_navigation_badge_counts_refunds_in_progress_with_payments_awaiting_confirmation(): void
    {
        $this->assertNull(PaymentResource::getNavigationBadge());

        config(['billing.confirmation.second_person' => true]);
        $this->record();
        config(['billing.confirmation.second_person' => false]);
        $this->stuck();
        $this->stuck();

        $this->assertSame('3', PaymentResource::getNavigationBadge());
        $this->assertSame('danger', PaymentResource::getNavigationBadgeColor());
        $this->assertSame(__('billing::billing.ui.payment.attention_badge', ['awaiting' => 1, 'refunding' => 2]), PaymentResource::getNavigationBadgeTooltip());
    }

    public function test_the_failure_reason_filter_finds_payments_that_may_have_been_paid(): void
    {
        $unverifiable = app(OnlinePaymentSettler::class)->abandon($this->startCheckout(), FailureReason::Unverifiable);
        $abandoned = app(OnlinePaymentSettler::class)->abandon($this->startCheckout());
        $confirmed = $this->record();

        Livewire::test(ListPayments::class)
            ->assertTableColumnExists('failure_reason')
            ->assertTableColumnFormattedStateSet('failure_reason', FailureReason::Unverifiable->label(), $unverifiable)
            ->filterTable('failure_reason', FailureReason::Unverifiable->value)
            ->assertCanSeeTableRecords([$unverifiable])
            ->assertCanNotSeeTableRecords([$abandoned, $confirmed]);
    }

    public function test_a_failure_reason_this_version_does_not_know_is_shown_as_stored(): void
    {
        $payment = app(OnlinePaymentSettler::class)->abandon($this->startCheckout());
        Payment::query()->whereKey($payment->id)->update(['failure_reason' => 'host_custom_reason']);

        Livewire::test(ListPayments::class)
            ->assertCanSeeTableRecords([$payment])
            ->assertTableColumnFormattedStateSet('failure_reason', 'host_custom_reason', $payment);
        Livewire::test(ViewPayment::class, ['record' => $payment->id])->assertSee('host_custom_reason');
        $this->assertNull($payment->refresh()->failureReason());
    }

    /** A confirmed payment with paid cover, left "refunding" by a refund whose outcome is unknown. */
    private function stuck(): Payment
    {
        $payment = $this->record();
        Payment::query()->whereKey($payment->id)->update(['status' => PaymentStatus::Refunding->value]);

        return $payment->refresh();
    }
}
