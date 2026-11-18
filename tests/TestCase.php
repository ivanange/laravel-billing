<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Ivanange\Billing\BillingServiceProvider;
use Ivanange\Billing\Data\PaymentDraft;
use Ivanange\Billing\Enums\DurationUnit;
use Ivanange\Billing\Gateways\GatewayActivation;
use Ivanange\Billing\Gateways\Providers\HubtelProvider;
use Ivanange\Billing\Gateways\Providers\NotchPayProvider;
use Ivanange\Billing\Gateways\Providers\OfflineProvider;
use Ivanange\Billing\Gateways\Providers\PaystackProvider;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Models\Plan;
use Ivanange\Billing\Periods\DurationTerm;
use Ivanange\Billing\Periods\Term;
use Ivanange\Billing\Services\PaymentRecorder;
use Ivanange\Billing\Tests\Fixtures\Member;
use Ivanange\Billing\Tests\Fixtures\User;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [BillingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', $this->databaseConnection($app));
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('billing.providers', [OfflineProvider::class, PaystackProvider::class, NotchPayProvider::class, HubtelProvider::class]);
    }

    /**
     * In-memory SQLite by default. Set DB_CONNECTION (mysql, mariadb, pgsql)
     * plus DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD to run
     * the suite on a real server; Testbench's database config reads them.
     */
    private function databaseConnection(Application $app): string
    {
        $connection = (string) env('DB_CONNECTION', 'sqlite');

        if (in_array($connection, ['sqlite', 'testing'], true)) {
            return 'testing';
        }

        if (! is_array($app['config']->get("database.connections.{$connection}"))) {
            throw new InvalidArgumentException("Unknown DB_CONNECTION [{$connection}] for the test suite.");
        }

        return $connection;
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-03-10 09:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    protected function plan(array $attributes = []): Plan
    {
        return Plan::query()->create($attributes + ['name' => 'Forecast SMS', 'currency' => 'GHS', 'grace_days' => 0]);
    }

    protected function gateway(array $attributes = [], bool $active = true): GatewayInstance
    {
        $instance = GatewayInstance::query()->create($attributes + [
            'name' => 'Cash office',
            'provider' => OfflineProvider::key(),
            'enabled_methods' => [OfflineProvider::CASH, OfflineProvider::MOBILE_MONEY, OfflineProvider::COMPLIMENTARY],
            'settings' => [],
        ]);

        if ($active) {
            app(GatewayActivation::class)->activate($instance);
        }

        return $instance->refresh();
    }

    protected function paystack(string $name = 'Paystack Ghana', string $secret = 'sk_test_ghana', bool $active = true): GatewayInstance
    {
        return $this->gateway([
            'name' => $name,
            'provider' => PaystackProvider::key(),
            'credentials' => ['secret_key' => $secret],
            'enabled_methods' => [PaystackProvider::CARD, PaystackProvider::MOBILE_MONEY],
            'currencies' => ['GHS'],
        ], $active);
    }

    /**
     * @param  array<string, string>  $credentials
     */
    protected function notchpay(string $name = 'NotchPay Cameroun', array $credentials = [], bool $active = true): GatewayInstance
    {
        return $this->gateway([
            'name' => $name,
            'provider' => NotchPayProvider::key(),
            'credentials' => $credentials + ['public_key' => 'pk_test_cm', 'private_key' => 'sk_test_cm', 'hash_key' => 'hsk_test_cm'],
            'enabled_methods' => [NotchPayProvider::MTN, NotchPayProvider::ORANGE],
            'currencies' => ['XAF'],
        ], $active);
    }

    /**
     * @param  array<string, string>  $credentials
     */
    protected function hubtel(string $name = 'Hubtel Ghana', array $credentials = [], bool $active = true): GatewayInstance
    {
        return $this->gateway([
            'name' => $name,
            'provider' => HubtelProvider::key(),
            'credentials' => $credentials + ['api_id' => 'hubtel-id', 'api_key' => 'hubtel-key'],
            'settings' => ['merchant_account' => '2017101'],
            'enabled_methods' => [HubtelProvider::CHECKOUT],
            'currencies' => ['GHS'],
        ], $active);
    }

    protected function member(string $name = 'Ama'): Member
    {
        return Member::query()->create(['name' => $name]);
    }

    protected function user(string $name = 'Kofi'): User
    {
        return User::query()->create(['name' => $name, 'email' => strtolower($name).'@example.test']);
    }

    protected function months(int $count = 1): Term
    {
        return new DurationTerm(DurationUnit::Month, $count);
    }

    /**
     * @param  array<string, mixed>  $overrides  PaymentDraft named arguments
     */
    protected function record(array $overrides = [], ?User $by = null): Payment
    {
        // Defaults are built lazily: creating an unused gateway would
        // deactivate a passed-in one of the same provider.
        $overrides['billables'] ??= [$this->member()];
        $overrides['plan'] ??= $this->plan();
        $overrides['term'] ??= $this->months();
        $overrides['gateway'] ??= $this->gateway();
        $overrides['method'] ??= OfflineProvider::CASH;
        $overrides['amountPerBillable'] ??= 500;

        $draft = new PaymentDraft(...$overrides);

        return app(PaymentRecorder::class)->record($draft, $by);
    }
}
