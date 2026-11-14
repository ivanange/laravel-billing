<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ivanange\Billing\Migration\KompassInspector;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Tests\TestCase;

/**
 * Critical path 32: the Kompass dry run reads Kompass-shaped tables
 * (columns as in Kompass's migrations) and writes nothing.
 */
class KompassDryRunTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('payment_drivers', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('driver_type');
            $t->boolean('is_active');
            $t->longText('config')->nullable();
            $t->softDeletes();
        });
        Schema::create('subscriptions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->unsignedBigInteger('payer_user_id')->nullable();
            $t->unsignedBigInteger('payment_driver_id');
            $t->string('status');
            $t->char('currency', 3);
            $t->integer('amount_cents');
            $t->unsignedTinyInteger('anchor_day');
            $t->softDeletes();
        });
        Schema::create('subscription_billing_cycles', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('subscription_id');
            $t->date('period_start');
            $t->date('period_end');
            $t->integer('amount_cents');
            $t->char('currency', 3);
            $t->string('status');
        });

        DB::table('payment_drivers')->insert([
            ['name' => 'SEPA', 'driver_type' => 'sepa_offline', 'is_active' => true],
            ['name' => 'PayPal A', 'driver_type' => 'paypal', 'is_active' => true],
            ['name' => 'PayPal B', 'driver_type' => 'paypal', 'is_active' => true],
        ]);
        DB::table('subscriptions')->insert([
            ['user_id' => 1, 'payer_user_id' => null, 'payment_driver_id' => 1, 'status' => 'active', 'currency' => 'EUR', 'amount_cents' => 8400, 'anchor_day' => 31],
            ['user_id' => 2, 'payer_user_id' => 3, 'payment_driver_id' => 2, 'status' => 'active', 'currency' => 'EUR', 'amount_cents' => 10000, 'anchor_day' => 1],
        ]);
        DB::table('subscriptions')->insert(['user_id' => 9, 'payer_user_id' => null, 'payment_driver_id' => 1, 'status' => 'cancelled', 'currency' => 'USD', 'amount_cents' => 999, 'anchor_day' => 1, 'deleted_at' => now()]);
        DB::table('subscription_billing_cycles')->insert([
            ['subscription_id' => 1, 'period_start' => '2026-01-31', 'period_end' => '2026-02-27', 'amount_cents' => 8400, 'currency' => 'EUR', 'status' => 'paid'],
            ['subscription_id' => 1, 'period_start' => '2026-02-28', 'period_end' => '2026-03-30', 'amount_cents' => 8400, 'currency' => 'EUR', 'status' => 'overdue'],
            ['subscription_id' => 2, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'amount_cents' => 10000, 'currency' => 'EUR', 'status' => 'paid'],
            ['subscription_id' => 2, 'period_start' => '2026-01-15', 'period_end' => '2026-02-14', 'amount_cents' => 10000, 'currency' => 'EUR', 'status' => 'waived'],
            ['subscription_id' => 3, 'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'amount_cents' => 999, 'currency' => 'USD', 'status' => 'paid'],
        ]);
    }

    public function test_the_report_maps_drivers_cycles_and_plans(): void
    {
        $report = app(KompassInspector::class)->report();

        $this->assertSame(['offline', 'offline', 'offline'], array_column($report['drivers'], 'provider'));
        $this->assertSame(['paypal'], $report['duplicates_active']);
        $this->assertSame(1, $report['subscriptions_without_payer']);
        $this->assertSame(['overdue' => 1, 'paid' => 2, 'waived' => 1], $report['cycles'], 'the soft-deleted subscription is left out');
        $this->assertSame(2, $report['payments_to_create']);
        $this->assertSame(['EUR 84.00 / month', 'EUR 100.00 / month'], $report['plans_to_create']);
        $this->assertSame(['EUR'], $report['currencies']);
        $this->assertSame(1, $report['overlapping_cycles']);
    }

    public function test_the_command_prints_the_plan_and_writes_nothing(): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower(ltrim($query->sql));
        });

        $this->artisan('billing:kompass-dry-run')
            ->expectsOutputToContain('Several active [paypal] drivers')
            ->expectsOutputToContain('Dry run: nothing was written.')
            ->assertSuccessful();

        $writes = array_filter($statements, fn (string $sql): bool => ! str_starts_with($sql, 'select') && ! str_starts_with($sql, 'pragma'));
        $this->assertNotEmpty($statements);
        $this->assertSame([], array_values($writes), 'the dry run only reads');
        $this->assertSame(0, GatewayInstance::query()->count());
        $this->assertSame(3, DB::table('payment_drivers')->count());
    }
}
