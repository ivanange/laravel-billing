<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Gateways\GatewayActivation;
use Ivanange\Billing\Gateways\GatewayCatalog;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Subscription;
use Ivanange\Billing\Tests\TestCase;

class GatewayInstanceTest extends TestCase
{
    public function test_activating_an_instance_switches_off_the_other_instance_of_that_provider_only(): void
    {
        $tamale = $this->gateway(['name' => 'Cash: Tamale']);
        $bimbilla = $this->gateway(['name' => 'Cash: Bimbilla'], active: false);
        $other = GatewayInstance::query()->create(['name' => 'Other provider row', 'provider' => 'other', 'enabled_methods' => []]);
        $other->forceFill(['is_active' => true])->save();

        app(GatewayActivation::class)->activate($bimbilla);

        $this->assertFalse($tamale->refresh()->is_active);
        $this->assertTrue($bimbilla->refresh()->is_active);
        $this->assertTrue($other->refresh()->is_active, 'other providers are untouched');
    }

    public function test_several_can_be_active_when_the_rule_is_off(): void
    {
        config(['billing.gateways.one_active_per_provider' => false]);
        $a = $this->gateway(['name' => 'A']);
        $b = $this->gateway(['name' => 'B']);

        $this->assertTrue($a->refresh()->is_active);
        $this->assertTrue($b->refresh()->is_active);
        $this->assertSame(['A', 'B'], array_values(app(GatewayCatalog::class)->instanceOptions('GHS')));
    }

    public function test_switching_the_active_instance_never_reroutes_existing_payments(): void
    {
        $old = $this->gateway(['name' => 'Old account', 'settings' => ['instructions' => 'Pay 024 000 0001']]);
        $payment = $this->record(['gateway' => $old]);

        $new = $this->gateway(['name' => 'New account', 'settings' => ['instructions' => 'Pay 024 000 0002']]);

        $this->assertFalse($old->refresh()->is_active);
        $this->assertSame('Old account', $payment->refresh()->gatewayInstance->name);
        $this->assertSame('Pay 024 000 0001', $payment->gatewayInstance->setting('instructions'));
        $this->assertSame($old->id, Subscription::query()->sole()->gateway_instance_id);

        $this->expectExceptionMessage('This payment gateway is not active.');
        $this->record(['gateway' => $old]);
    }

    public function test_deleted_instances_keep_serving_existing_payments(): void
    {
        $instance = $this->gateway();
        $payment = $this->record(['gateway' => $instance]);

        $instance->delete();

        $this->assertSoftDeleted($instance);
        $this->assertSame('Cash', $payment->refresh()->methodLabel());
        $this->assertSame([], app(GatewayCatalog::class)->instanceOptions());

        $this->expectException(BillingException::class);
        app(GatewayActivation::class)->activate($instance);
    }

    public function test_credentials_are_encrypted_at_rest_and_hidden(): void
    {
        $instance = $this->gateway(['credentials' => ['secret_key' => 'sk_live_supersecret']]);

        $raw = DB::table('billing_gateway_instances')->where('id', $instance->id)->value('credentials');

        $this->assertStringNotContainsString('supersecret', (string) $raw);
        $this->assertSame('sk_live_supersecret', $instance->refresh()->credentials['secret_key']);
        $this->assertArrayNotHasKey('credentials', $instance->toArray());
    }

    public function test_the_catalog_offers_only_enabled_methods_of_active_instances_in_the_currency(): void
    {
        $momo = $this->gateway(['name' => 'MoMo', 'enabled_methods' => ['mobile_money', 'not-a-method']]);
        GatewayInstance::query()->create(['name' => 'Nothing enabled', 'provider' => 'offline', 'enabled_methods' => []])
            ->forceFill(['is_active' => true])->save();
        config(['billing.gateways.one_active_per_provider' => false]);
        $this->gateway(['name' => 'Francs only', 'currencies' => ['XAF']]);

        $catalog = app(GatewayCatalog::class);

        $this->assertSame([$momo->id => 'MoMo'], $catalog->instanceOptions('GHS'));
        $this->assertSame(['mobile_money' => 'Mobile money (sent to our number)'], $catalog->methodOptions($momo->id));
        $this->assertSame([], $catalog->methodOptions(999));
    }
}
