<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature\Filament;

use DateTimeInterface;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Ivanange\Billing\Contracts\Billable;
use Ivanange\Billing\Data\ChargeResult;
use Ivanange\Billing\Enums\ChargeStatus;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Filament\Resources\GatewayInstances\Pages\CreateGatewayInstance;
use Ivanange\Billing\Filament\Resources\GatewayInstances\Pages\ListGatewayInstances;
use Ivanange\Billing\Filament\Resources\Payments\Pages\ListPayments;
use Ivanange\Billing\Filament\Resources\Payments\Pages\ViewPayment;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Models\Plan;
use Ivanange\Billing\Models\PlanPrice;
use Ivanange\Billing\Services\OnlinePaymentSettler;
use Ivanange\Billing\Services\PayLinks;
use Ivanange\Billing\Tests\FilamentTestCase;
use Ivanange\Billing\Tests\Fixtures\Filament\MemberResource;
use Ivanange\Billing\Tests\Fixtures\Filament\Pages\EditMember;
use Ivanange\Billing\Tests\Fixtures\Filament\Pages\ListMembers;
use Ivanange\Billing\Tests\Support\OnlineHelpers;
use Livewire\Livewire;

class FilamentActionsTest extends FilamentTestCase
{
    use OnlineHelpers;

    public function test_record_payment_action_records_cover_and_offers_offline_gateways_only(): void
    {
        $member = $this->member();
        $price = $this->monthlyPrice();
        $cash = $this->gateway();
        $this->paystack();

        Livewire::test(ListMembers::class)
            ->mountAction(TestAction::make('recordPayment')->table($member))
            ->assertFormFieldExists('gateway_instance_id')
            ->callMountedAction()
            ->assertHasFormErrors();

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('recordPayment')->table($member), [
                'plan_id' => $price->plan_id,
                'price_id' => $price->id,
                'amount' => 15,
                'gateway_instance_id' => $cash->id,
                'method' => 'cash',
                'paid_on' => '2026-03-10',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(PaymentStatus::Confirmed, Payment::query()->sole()->status);
        $this->assertNotNull($member->paidUntil());
    }

    public function test_pay_link_action_generates_a_working_signed_link(): void
    {
        $member = $this->member();
        $price = $this->monthlyPrice();
        $this->paystack();
        $links = new class extends PayLinks
        {
            public ?string $last = null;

            public function make(Model&Billable $billable, Plan $plan, PlanPrice $price, ?DateTimeInterface $expiresAt = null): string
            {
                return $this->last = parent::make($billable, $plan, $price, $expiresAt);
            }
        };
        $this->app->instance(PayLinks::class, $links);

        Livewire::test(ListMembers::class)
            ->callAction(TestAction::make('payLink')->table($member), ['plan_id' => $price->plan_id, 'price_id' => $price->id, 'days' => 3])
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertNotNull($links->last);
        $this->get($links->last)->assertOk()->assertSee('Mobile money');
    }

    public function test_confirm_void_and_refund_actions(): void
    {
        config(['billing.confirmation.second_person' => true]);
        $recorded = $this->record([], $this->user('Maker'));

        Livewire::test(ListPayments::class)->callAction(TestAction::make('confirmPayment')->table($recorded));
        $this->assertSame(PaymentStatus::Confirmed, $recorded->refresh()->status);

        Livewire::test(ViewPayment::class, ['record' => $recorded->id])
            ->assertActionHidden('refundPayment')
            ->callAction('voidPayment', ['reason' => 'Duplicate']);
        $this->assertSame(PaymentStatus::Void, $recorded->refresh()->status);

        $online = $this->startCheckout();
        app(OnlinePaymentSettler::class)->settle($online, new ChargeResult(ChargeStatus::Succeeded, (string) $online->reference, 1500, 'GHS'));
        Http::fake(['api.paystack.co/refund' => Http::response(['status' => true, 'message' => 'Refund has been queued for processing', 'data' => ['id' => 1, 'amount' => 1500, 'currency' => 'GHS']])]);

        Livewire::test(ViewPayment::class, ['record' => $online->id])->assertActionHidden('refundPayment');
        Gate::define('billing.refund', fn (): bool => true);
        Livewire::test(ViewPayment::class, ['record' => $online->id])->callAction('refundPayment');
        $this->assertSame(PaymentStatus::Refunded, $online->refresh()->status);
    }

    public function test_pay_link_action_respects_the_record_ability_per_billable(): void
    {
        $allowed = $this->member('Allowed');
        $denied = $this->member('Denied');
        Gate::define('billing.record', fn ($user, $billable = null): bool => $billable?->name !== 'Denied');

        Livewire::test(ListMembers::class)
            ->assertActionVisible(TestAction::make('payLink')->table($allowed))
            ->assertActionHidden(TestAction::make('payLink')->table($denied));
    }

    public function test_gateway_instances_create_with_provider_fields_and_activate(): void
    {
        Livewire::test(CreateGatewayInstance::class)
            ->fillForm([
                'name' => 'Paystack Ghana',
                'provider' => 'paystack',
                'enabled_methods' => ['card', 'mobile_money'],
                'credentials.secret_key' => 'sk_test_new',
                'activate_now' => false,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $instance = GatewayInstance::query()->where('name', 'Paystack Ghana')->sole();
        $this->assertSame('sk_test_new', $instance->credentials['secret_key']);

        Livewire::test(ListGatewayInstances::class)->callAction(TestAction::make('activate')->table($instance));
        $this->assertTrue($instance->refresh()->is_active);
    }

    public function test_the_member_edit_page_shows_paid_cover(): void
    {
        $payment = $this->record();
        $member = $payment->coveragePeriods()->first()->billable;

        $this->get(MemberResource::getUrl('edit', ['record' => $member]))->assertOk();
        Livewire::test(EditMember::class, ['record' => $member->getKey()])->assertOk();
    }
}
