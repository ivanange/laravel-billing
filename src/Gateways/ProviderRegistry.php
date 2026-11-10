<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways;

use InvalidArgumentException;
use Ivanange\Billing\Contracts\PaymentProvider;

/**
 * Providers are registered in code (config billing.providers), never as a
 * database enum, so adding one needs no schema change.
 */
class ProviderRegistry
{
    /** @var array<string, class-string<PaymentProvider>> */
    private array $providers = [];

    /**
     * @param  class-string<PaymentProvider>  $class
     */
    public function register(string $class): void
    {
        if (! is_subclass_of($class, PaymentProvider::class)) {
            throw new InvalidArgumentException("{$class} must implement ".PaymentProvider::class);
        }

        $this->providers[$class::key()] = $class;
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }

    public function get(string $key): PaymentProvider
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Unknown payment provider [{$key}].");
        }

        return app($this->providers[$key]);
    }

    /**
     * @return array<string, string> key => label
     */
    public function options(): array
    {
        return array_map(fn (string $class): string => $class::label(), $this->providers);
    }
}
