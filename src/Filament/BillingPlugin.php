<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Ivanange\Billing\Filament\Resources\Calendars\BillingCalendarResource;
use Ivanange\Billing\Filament\Resources\GatewayInstances\GatewayInstanceResource;
use Ivanange\Billing\Filament\Resources\Payments\PaymentResource;
use Ivanange\Billing\Filament\Resources\Plans\PlanResource;
use Ivanange\Billing\Filament\Resources\Subscriptions\SubscriptionResource;

/**
 * $panel->plugin(BillingPlugin::make()->billables([Farmer::class => 'Farmers']))
 *
 * Add RecordPaymentAction / RecordGroupPaymentBulkAction and the
 * CoveragePeriodsRelationManager to your billable resources.
 */
class BillingPlugin implements Plugin
{
    private ?string $navigationGroup = null;

    /** @var array<class-string, string> */
    private array $billables = [];

    private bool $calendars = true;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(app(static::class)->getId());
    }

    public function getId(): string
    {
        return 'billing';
    }

    public function navigationGroup(?string $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    public function getNavigationGroup(): string
    {
        return $this->navigationGroup ?? __('billing::billing.ui.navigation_group');
    }

    /**
     * Models plans can be limited to, e.g. [Farmer::class => 'Farmers'].
     *
     * @param  array<class-string, string>  $billables
     */
    public function billables(array $billables): static
    {
        $this->billables = $billables;

        return $this;
    }

    /**
     * @return array<string, string> morph class => label
     */
    public function getBillableOptions(): array
    {
        $options = [];
        foreach ($this->billables as $class => $label) {
            $options[(new $class)->getMorphClass()] = $label;
        }

        return $options;
    }

    /** Hide school-term style calendars when an app only sells durations. */
    public function calendars(bool $enabled = true): static
    {
        $this->calendars = $enabled;

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel->resources(array_values(array_filter([
            PaymentResource::class,
            SubscriptionResource::class,
            PlanResource::class,
            $this->calendars ? BillingCalendarResource::class : null,
            GatewayInstanceResource::class,
        ])));
    }

    public function boot(Panel $panel): void {}
}
