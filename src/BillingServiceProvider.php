<?php

declare(strict_types=1);

namespace Ivanange\Billing;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Ivanange\Billing\Console\CheckCoverageCommand;
use Ivanange\Billing\Console\ReconcilePaymentsCommand;
use Ivanange\Billing\Gateways\ProviderRegistry;

class BillingServiceProvider extends ServiceProvider
{
    /** Abilities the app may define itself; defaults come from billing.authorize_by_default. */
    public const ABILITIES = ['billing.view', 'billing.record', 'billing.confirm', 'billing.void', 'billing.manage'];

    /** Abilities that move money: denied unless the app defines them (or billing.authorize_refunds_by_default is true). */
    public const MONEY_ABILITIES = ['billing.refund'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/billing.php', 'billing');

        $this->app->singleton(ProviderRegistry::class, function (): ProviderRegistry {
            $registry = new ProviderRegistry;

            foreach ((array) config('billing.providers', []) as $provider) {
                $registry->register($provider);
            }

            return $registry;
        });
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'billing');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'billing');
        $this->defineRateLimiters();
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if (config('billing.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCoverageCommand::class, ReconcilePaymentsCommand::class]);
            $this->publishes([__DIR__.'/../config/billing.php' => config_path('billing.php')], 'billing-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'billing-migrations');
            $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/billing')], 'billing-lang');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/billing')], 'billing-views');
        }

        $this->app->booted(fn () => $this->defineDefaultAbilities());
    }

    private function defineRateLimiters(): void
    {
        // Keyed by instance AND ip: behind an untrusted proxy every caller
        // shares one ip, and noise on one instance's URL must not starve another.
        RateLimiter::for('billing-webhooks', fn (Request $request): Limit => Limit::perMinute((int) config('billing.webhooks.rate_limit', 120))
            ->by('billing-webhooks|'.$request->route('instance').'|'.$request->ip()));
        RateLimiter::for('billing-pay', function (Request $request): array {
            $limits = [Limit::perMinute((int) config('billing.pay_links.rate_limit', 30))->by('billing-pay|'.$request->ip())];
            $reference = $request->route('reference');

            // Each return-page hit may call the provider: also cap per reference.
            // The pay page itself has no reference; an empty key would make every
            // payer on the site share one bucket.
            if (is_string($reference) && $reference !== '') {
                $limits[] = Limit::perMinute(10)->by('billing-pay-ref|'.$reference);
            }

            // Each POST creates a pending payment and a provider checkout: cap
            // them per pay link (its signature), whatever ip they come from.
            $signature = $request->query('signature');
            if ($request->isMethod('POST') && is_string($signature) && $signature !== '') {
                $limits[] = Limit::perHour((int) config('billing.pay_links.checkouts_per_link_per_hour', 20))
                    ->by('billing-pay-link|'.hash('sha256', $signature));
            }

            return $limits;
        });
    }

    /**
     * Runs after every provider has booted, so abilities the app defined
     * itself always win.
     */
    private function defineDefaultAbilities(): void
    {
        foreach (self::ABILITIES as $ability) {
            if (! Gate::has($ability)) {
                Gate::define($ability, fn ($user): bool => (bool) config('billing.authorize_by_default', true));
            }
        }

        foreach (self::MONEY_ABILITIES as $ability) {
            if (! Gate::has($ability)) {
                Gate::define($ability, fn ($user): bool => (bool) config('billing.authorize_refunds_by_default', false));
            }
        }
    }
}
