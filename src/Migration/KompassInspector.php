<?php

declare(strict_types=1);

namespace Ivanange\Billing\Migration;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Ivanange\Billing\Support\Money;

/**
 * Read-only look at a Kompass database to plan moving its billing into
 * this package (see docs/KOMPASS_MIGRATION.md). It never writes.
 *
 * Kompass tables: payment_drivers, subscriptions,
 * subscription_billing_cycles. Soft-deleted subscriptions (and their
 * cycles) are left out, as the import would leave them out.
 */
class KompassInspector
{
    /** Kompass driver_type => [our provider, our method, note]. */
    public const PROVIDER_MAP = [
        'sepa_offline' => ['offline', 'bank_transfer', null],
        'free' => ['offline', 'complimentary', null],
        'paypal' => ['offline', 'paypal', 'archived instance, history only'],
        'stripe' => [null, 'card', 'not migrated (no Stripe provider yet)'],
    ];

    /** Kompass cycle status => [our period status, whether a Payment is created]. */
    public const CYCLE_MAP = [
        'paid' => ['paid', true],
        'waived' => ['paid', false],
        'pending' => ['pending', false],
        'overdue' => ['pending', false],
        'failed' => ['pending', false],
    ];

    public function __construct(private readonly ConnectionInterface $db) {}

    /**
     * @return array{drivers: list<array<string, mixed>>, duplicates_active: list<string>, subscriptions: int, subscriptions_without_payer: int, cycles: array<string, int>, payments_to_create: int, plans_to_create: list<string>, currencies: list<string>, overlapping_cycles: int}
     */
    public function report(): array
    {
        $cycles = $this->cycles()
            ->selectRaw('subscription_billing_cycles.status as status, count(*) as total')
            ->groupBy('subscription_billing_cycles.status')
            ->pluck('total', 'status')
            ->map(fn ($n): int => (int) $n)
            ->sortKeys()
            ->all();

        return [
            'drivers' => $this->drivers(),
            'duplicates_active' => $this->duplicatesActive(),
            'subscriptions' => $this->subscriptions()->count(),
            'subscriptions_without_payer' => $this->subscriptions()->whereNull('payer_user_id')->count(),
            'cycles' => $cycles,
            'payments_to_create' => array_sum(array_intersect_key($cycles, array_filter(array_map(fn (array $m): bool => $m[1], self::CYCLE_MAP)))),
            'plans_to_create' => $this->plans(),
            'currencies' => $this->subscriptions()->distinct()->orderBy('currency')->pluck('currency')->map(fn ($c): string => (string) $c)->all(),
            'overlapping_cycles' => $this->overlaps(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function drivers(): array
    {
        return $this->db->table('payment_drivers')->orderBy('id')->get()->map(fn (object $d): array => [
            'id' => (int) $d->id,
            'name' => (string) $d->name,
            'type' => (string) $d->driver_type,
            'active' => (bool) $d->is_active,
            'deleted' => ($d->deleted_at ?? null) !== null,
            'provider' => self::PROVIDER_MAP[$d->driver_type][0] ?? null,
            'note' => self::PROVIDER_MAP[$d->driver_type][2] ?? null,
        ])->values()->all();
    }

    /**
     * @return list<string>
     */
    private function duplicatesActive(): array
    {
        return $this->withoutTrashed($this->db->table('payment_drivers'), 'payment_drivers')
            ->where('is_active', true)
            ->groupBy('driver_type')
            ->havingRaw('count(*) > 1')
            ->orderBy('driver_type')
            ->pluck('driver_type')
            ->map(fn ($t): string => (string) $t)
            ->all();
    }

    /**
     * Kompass has no plan table: one monthly plan per (currency, amount).
     *
     * @return list<string>
     */
    private function plans(): array
    {
        return $this->subscriptions()
            ->select(['amount_cents', 'currency'])
            ->distinct()
            ->orderBy('currency')->orderBy('amount_cents')
            ->get()
            ->map(fn (object $s): string => sprintf('%s %s / month', $s->currency, number_format(Money::toMajor((int) $s->amount_cents, (string) $s->currency), Money::decimals((string) $s->currency), '.', '')))
            ->all();
    }

    /**
     * Cycles of one subscription whose dates overlap the previous one (both
     * ends inclusive, as Kompass stores them), e.g. a PayPal calendar month
     * after a SEPA anchor-day month. Streams rows instead of loading them.
     */
    private function overlaps(): int
    {
        $count = 0;
        $previous = null;

        foreach ($this->cycles()->select(['subscription_billing_cycles.subscription_id', 'period_start', 'period_end'])
            ->orderBy('subscription_billing_cycles.subscription_id')->orderBy('period_start')->cursor() as $cycle) {
            if ($previous !== null && $previous->subscription_id === $cycle->subscription_id && (string) $cycle->period_start <= (string) $previous->period_end) {
                $count++;
            }
            $previous = $cycle;
        }

        return $count;
    }

    private function subscriptions(): Builder
    {
        return $this->withoutTrashed($this->db->table('subscriptions'), 'subscriptions');
    }

    private function cycles(): Builder
    {
        return $this->db->table('subscription_billing_cycles')
            ->whereIn('subscription_billing_cycles.subscription_id', $this->subscriptions()->select('subscriptions.id'));
    }

    private function withoutTrashed(Builder $query, string $table): Builder
    {
        $softDeletes = $this->db instanceof Connection && $this->db->getSchemaBuilder()->hasColumn($table, 'deleted_at');

        return $softDeletes ? $query->whereNull("{$table}.deleted_at") : $query;
    }
}
