<?php

declare(strict_types=1);

namespace Ivanange\Billing;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Ivanange\Billing\Console\CheckCoverageCommand;
use Ivanange\Billing\Gateways\ProviderRegistry;

class BillingServiceProvider extends ServiceProvider
{
    /** Abilities the app may define itself; defaults come from billing.authorize_by_default. */
    public const ABILITIES = ['billing.view', 'billing.record', 'billing.confirm', 'billing.void', 'billing.manage'];

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
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if (config('billing.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckCoverageCommand::class]);
            $this->publishes([__DIR__.'/../config/billing.php' => config_path('billing.php')], 'billing-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'billing-migrations');
            $this->publishes([__DIR__.'/../lang' => $this->app->langPath('vendor/billing')], 'billing-lang');
        }

        $this->app->booted(fn () => $this->defineDefaultAbilities());
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
    }
}
