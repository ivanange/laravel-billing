<?php

declare(strict_types=1);

namespace Ivanange\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Ivanange\Billing\Contracts\PaymentProvider;
use Ivanange\Billing\Gateways\PaymentMethod;
use Ivanange\Billing\Gateways\ProviderRegistry;

/**
 * One configured account of a payment provider, e.g. "Cash: Tamale office"
 * or "Paystack Ghana". Payments always keep the instance they were made
 * with, so changing which instance is active never re-routes old payments.
 *
 * @property int $id
 * @property string $name
 * @property string $provider
 * @property array<string, mixed>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property list<string>|null $enabled_methods
 * @property list<string>|null $currencies
 * @property bool $is_active
 */
class GatewayInstance extends Model
{
    use SoftDeletes;

    protected $table = 'billing_gateway_instances';

    /** is_active is not fillable: use GatewayActivation so the one-per-provider rule holds. */
    protected $fillable = ['name', 'provider', 'credentials', 'settings', 'enabled_methods', 'currencies', 'sort'];

    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'enabled_methods' => 'array',
            'currencies' => 'array',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function gateway(): PaymentProvider
    {
        return app(ProviderRegistry::class)->get($this->provider);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Methods this instance offers: enabled by the admin AND supported by the provider.
     *
     * @return list<PaymentMethod>
     */
    public function offeredMethods(): array
    {
        $enabled = (array) $this->enabled_methods;

        return array_values(array_filter(
            $this->gateway()->methods(),
            fn (PaymentMethod $method): bool => in_array($method->key, $enabled, true),
        ));
    }

    public function offersMethod(string $method): bool
    {
        foreach ($this->offeredMethods() as $offered) {
            if ($offered->key === $method) {
                return true;
            }
        }

        return false;
    }

    public function methodLabel(string $method): string
    {
        foreach ($this->gateway()->methods() as $known) {
            if ($known->key === $method) {
                return $known->label();
            }
        }

        return $method;
    }

    public function supportsCurrency(string $currency): bool
    {
        $currencies = array_map('strtoupper', (array) $this->currencies);

        return $currencies === [] || in_array(strtoupper($currency), $currencies, true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
