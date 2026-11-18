<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature\Online;

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Events\CoverageExtended;
use Ivanange\Billing\Events\PaymentConfirmed;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\PayLinks;
use Ivanange\Billing\Tests\Support\OnlineHelpers;
use Ivanange\Billing\Tests\TestCase;

class PayLinkTest extends TestCase
{
    use OnlineHelpers;

    private function link(array $gatewaySettings = []): string
    {
        $this->paystack();
        config(['billing.gateways.one_active_per_provider' => true]);
        $this->gateway(['name' => 'Cash office', 'settings' => $gatewaySettings + ['instructions' => "Send MoMo to 024 000 0001\nUse your name as reference"]]);
        $price = $this->monthlyPrice();

        return app(PayLinks::class)->make($this->member('Ama Mensah'), $price->plan, $price);
    }

    public function test_the_page_lists_online_methods_and_offline_instructions(): void
    {
        $this->get($this->link())
            ->assertOk()
            ->assertSee('Forecast SMS – 1 month')
            ->assertSee('For Ama Mensah')
            ->assertSee('Mobile money')
            ->assertSee('Paystack Ghana')
            ->assertSee('Send MoMo to 024 000 0001')
            ->assertSee('name="email"', false);
    }

    public function test_the_page_hides_inactive_instances_and_instances_without_the_plan_currency(): void
    {
        config(['billing.gateways.one_active_per_provider' => false]);
        $shown = $this->paystack('Paystack Ghana');
        $dormant = $this->paystack('Paystack Dormant', 'sk_test_dormant', active: false);
        $otherCurrency = $this->notchpay('NotchPay Cameroun');
        $price = $this->monthlyPrice();

        $this->get(app(PayLinks::class)->make($this->member(), $price->plan, $price))
            ->assertOk()
            ->assertSee('value="'.$shown->id.':mobile_money"', false)
            ->assertDontSee('Paystack Dormant')
            ->assertDontSee('value="'.$dormant->id.':', false)
            ->assertDontSee('NotchPay Cameroun')
            ->assertDontSee('value="'.$otherCurrency->id.':', false);
    }

    public function test_an_offline_instance_offers_no_online_choice_and_cannot_be_posted(): void
    {
        $url = $this->link();
        $offline = GatewayInstance::query()->where('provider', 'offline')->sole();
        Http::fake();

        $this->get($url)->assertOk()->assertSee('Cash office')->assertDontSee('value="'.$offline->id.':', false);

        $this->from($url)->post($url, ['choice' => "{$offline->id}:cash", 'email' => 'ama@example.test'])
            ->assertRedirect($url)
            ->assertSessionHasErrors(['choice' => 'This gateway does not take online payments.']);
        $this->assertSame(0, Payment::query()->count());
        Http::assertNothingSent();
    }

    public function test_tampered_or_expired_links_are_refused(): void
    {
        $url = $this->link();

        $this->get(str_replace('price=', 'price=9', $url))->assertForbidden();
        $this->get(preg_replace('/id=\d+/', 'id=999', $url))->assertForbidden();

        $this->travel(8)->days();
        $this->get($url)->assertForbidden();
    }

    public function test_links_only_for_prices_of_the_plan(): void
    {
        $price = $this->monthlyPrice();
        $this->expectException(BillingException::class);
        app(PayLinks::class)->make($this->member(), $this->plan(['name' => 'Other']), $price);
    }

    public function test_posting_starts_the_checkout_and_redirects_to_the_provider(): void
    {
        $url = $this->link();
        Http::fake(['api.paystack.co/transaction/initialize' => $this->paystackInitialized()]);
        $instance = GatewayInstance::query()->where('provider', 'paystack')->value('id');

        $this->post($url, ['choice' => "{$instance}:mobile_money", 'email' => 'ama@example.test', 'amount' => 1])
            ->assertRedirect('https://checkout.paystack.com/0peioxfhpn');

        $payment = Payment::query()->sole();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(1500, $payment->amount, 'the amount comes from the price, never the request');
        Http::assertSent(fn (HttpRequest $r): bool => $r['amount'] === 1500 && $r['callback_url'] === route('billing.pay.return', $payment->reference));
    }

    public function test_posting_an_offline_or_unknown_choice_is_refused(): void
    {
        $url = $this->link();

        $this->from($url)->post($url, ['choice' => '999:card', 'email' => 'ama@example.test'])->assertRedirect($url)->assertSessionHasErrors('choice');
        $this->post($url, ['choice' => 'nonsense'])->assertSessionHasErrors('choice');
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_return_page_verifies_with_the_provider_and_shows_the_result(): void
    {
        $payment = $this->startCheckout();
        Http::fake(["api.paystack.co/transaction/verify/{$payment->reference}" => $this->paystackTransaction((string) $payment->reference)]);

        $this->get(route('billing.pay.return', $payment->reference).'?status=success')
            ->assertOk()
            ->assertSee('Payment received. Thank you!');

        $this->assertSame(PaymentStatus::Confirmed, $payment->refresh()->status);
        Http::assertSent(fn (HttpRequest $r): bool => $r->header('Authorization') === ['Bearer sk_test_ghana']);
    }

    public function test_the_return_page_confirms_extends_cover_from_paid_until_and_announces_once(): void
    {
        $member = $this->member();
        $plan = $this->plan();
        $paidUntil = $this->record(['billables' => [$member], 'plan' => $plan])->coveragePeriods()->sole()->ends_on;
        $payment = $this->startCheckout($member, price: $this->monthlyPrice($plan));
        Http::fake(["api.paystack.co/transaction/verify/{$payment->reference}" => $this->paystackTransaction((string) $payment->reference)]);
        Event::fake([PaymentConfirmed::class, CoverageExtended::class]);

        $this->get(route('billing.pay.return', $payment->reference))->assertOk()->assertSee('Payment received. Thank you!');

        $this->assertSame(PaymentStatus::Confirmed, $payment->refresh()->status);
        $period = $payment->coveragePeriods()->sole();
        $this->assertSame($paidUntil->addDay()->toDateString(), $period->starts_on->toDateString());
        $this->assertTrue($member->paidUntil($plan)->equalTo($period->ends_on));
        Event::assertDispatchedTimes(PaymentConfirmed::class, 1);
        Event::assertDispatchedTimes(CoverageExtended::class, 1);
    }

    public function test_the_return_page_does_not_trust_the_query_string(): void
    {
        $payment = $this->startCheckout();
        Http::fake(['api.paystack.co/transaction/verify/*' => $this->paystackTransaction((string) $payment->reference, 'abandoned')]);

        $this->get(route('billing.pay.return', $payment->reference).'?status=success&trxref='.$payment->reference)
            ->assertOk()
            ->assertSee('We are waiting for the payment provider.');

        $this->assertSame(PaymentStatus::Pending, $payment->refresh()->status);
    }

    public function test_the_return_page_404s_for_unknown_or_offline_references(): void
    {
        $this->get(route('billing.pay.return', 'BIL-NOPE'))->assertNotFound();
        $offline = $this->record(['reference' => 'RCPT-1']);
        $this->get(route('billing.pay.return', $offline->reference))->assertNotFound();
    }
}
