<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Exceptions\NoDefaultPanelSetException;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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

    /** @var array<class-string, list<string>> */
    private array $billableSearchColumns = [];

    private ?Closure $payerQuery = null;

    private bool $payments = true;

    private bool $subscriptions = true;

    private bool $plans = true;

    private bool $calendars = true;

    private bool $gatewayInstances = true;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static */
        return filament(app(static::class)->getId());
    }

    /** The plugin on the current panel, or null outside a panel that registered it. */
    public static function current(): ?static
    {
        $panel = self::currentPanel();
        $plugin = $panel?->hasPlugin('billing') ? $panel->getPlugin('billing') : null;

        return $plugin instanceof static ? $plugin : null;
    }

    /**
     * The current panel, else the default one; null in a console command, a
     * job or a page outside Filament when no panel is the default.
     */
    public static function currentPanel(): ?Panel
    {
        if (! app()->bound('filament')) {
            return null;
        }

        try {
            return Filament::getCurrentPanel() ?? app(PanelRegistry::class)->getDefault();
        } catch (NoDefaultPanelSetException) {
            return null;
        }
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

    /**
     * Columns the payer picker searches, e.g. [Farmer::class => ['name', 'phone']].
     * Without an entry it searches a "name" column if the table has one.
     *
     * @param  array<class-string, list<string>>  $columns
     */
    public function billableSearchColumns(array $columns): static
    {
        $this->billableSearchColumns = $columns;

        return $this;
    }

    /**
     * @param  class-string  $class
     * @return list<string>|null null = not configured
     */
    public function getBillableSearchColumns(string $class): ?array
    {
        return $this->billableSearchColumns[$class] ?? null;
    }

    /**
     * Limit the records the payer picker may offer and accept, e.g.
     * fn (Builder $query, string $class) => $query->where('team_id', auth()->user()->team_id).
     * It gets the query for each registered billable class and may return a
     * new one. See the README for the tenant scope applied without it.
     *
     * @param  (Closure(Builder<Model>, class-string<Model>): (Builder<Model>|null|void))|null  $callback
     */
    public function payerQueryUsing(?Closure $callback): static
    {
        $this->payerQuery = $callback;

        return $this;
    }

    public function getPayerQueryUsing(): ?Closure
    {
        return $this->payerQuery;
    }

    public function payments(bool $enabled = true): static
    {
        $this->payments = $enabled;

        return $this;
    }

    public function subscriptions(bool $enabled = true): static
    {
        $this->subscriptions = $enabled;

        return $this;
    }

    public function plans(bool $enabled = true): static
    {
        $this->plans = $enabled;

        return $this;
    }

    /** Hide school-term style calendars when an app only sells durations. */
    public function calendars(bool $enabled = true): static
    {
        $this->calendars = $enabled;

        return $this;
    }

    public function gatewayInstances(bool $enabled = true): static
    {
        $this->gatewayInstances = $enabled;

        return $this;
    }

    public function register(Panel $panel): void
    {
        $panel->resources(array_values(array_filter([
            $this->payments ? PaymentResource::class : null,
            $this->subscriptions ? SubscriptionResource::class : null,
            $this->plans ? PlanResource::class : null,
            $this->calendars ? BillingCalendarResource::class : null,
            $this->gatewayInstances ? GatewayInstanceResource::class : null,
        ])));
    }

    public function boot(Panel $panel): void {}
}
