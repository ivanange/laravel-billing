<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways;

use Illuminate\Support\Collection;
use Ivanange\Billing\Models\GatewayInstance;

/**
 * What a person can choose from when paying: active instances and the
 * methods each one has enabled, filtered by currency.
 */
class GatewayCatalog
{
    /**
     * @return Collection<int, GatewayInstance>
     */
    public function instances(?string $currency = null): Collection
    {
        return GatewayInstance::query()
            ->active()
            ->orderBy('sort')
            ->orderBy('name')
            ->get()
            ->filter(fn (GatewayInstance $instance): bool => ($currency === null || $instance->supportsCurrency($currency))
                && $instance->offeredMethods() !== [])
            ->values();
    }

    /**
     * @return array<int, string> instance id => name
     */
    public function instanceOptions(?string $currency = null): array
    {
        return $this->instances($currency)->mapWithKeys(fn (GatewayInstance $i): array => [$i->id => $i->name])->all();
    }

    /**
     * @return array<string, string> method key => label
     */
    public function methodOptions(GatewayInstance|int|null $instance): array
    {
        $instance = is_int($instance) ? GatewayInstance::query()->active()->find($instance) : $instance;

        return collect($instance?->offeredMethods() ?? [])
            ->mapWithKeys(fn (PaymentMethod $m): array => [$m->key => $m->label()])
            ->all();
    }
}
