<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Feature\Filament;

use Filament\Panel;
use Ivanange\Billing\Filament\BillingPlugin;
use Ivanange\Billing\Filament\Resources\Calendars\BillingCalendarResource;
use Ivanange\Billing\Filament\Resources\GatewayInstances\GatewayInstanceResource;
use Ivanange\Billing\Filament\Resources\Payments\PaymentResource;
use Ivanange\Billing\Filament\Resources\Plans\PlanResource;
use Ivanange\Billing\Filament\Resources\Subscriptions\SubscriptionResource;
use Ivanange\Billing\Tests\FilamentTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BillingPluginTest extends FilamentTestCase
{
    private const ALL = [
        PaymentResource::class,
        SubscriptionResource::class,
        PlanResource::class,
        BillingCalendarResource::class,
        GatewayInstanceResource::class,
    ];

    public function test_every_resource_is_registered_by_default(): void
    {
        $this->assertSame(self::ALL, $this->registered(BillingPlugin::make()));
    }

    /**
     * @return iterable<string, array{0: string, 1: class-string}>
     */
    public static function toggles(): iterable
    {
        yield 'payments' => ['payments', PaymentResource::class];
        yield 'subscriptions' => ['subscriptions', SubscriptionResource::class];
        yield 'plans' => ['plans', PlanResource::class];
        yield 'calendars' => ['calendars', BillingCalendarResource::class];
        yield 'gateway instances' => ['gatewayInstances', GatewayInstanceResource::class];
    }

    #[DataProvider('toggles')]
    public function test_a_disabled_resource_is_not_registered(string $toggle, string $resource): void
    {
        $registered = $this->registered(BillingPlugin::make()->{$toggle}(false));

        $this->assertNotContains($resource, $registered);
        $this->assertSame(array_values(array_diff(self::ALL, [$resource])), $registered);
    }

    /**
     * @return list<class-string>
     */
    private function registered(BillingPlugin $plugin): array
    {
        $panel = Panel::make()->id('toggles');
        $plugin->register($panel);

        return array_values($panel->getResources());
    }
}
