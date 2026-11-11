<?php

declare(strict_types=1);

namespace Ivanange\Billing\Migration;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Read-only look at a Kompass database to plan moving its billing into
 * this package (see docs/KOMPASS_MIGRATION.md). It never writes.
 *
 * Kompass tables: payment_drivers, subscriptions,
 * subscription_billing_cycles.
 */
class KompassInspector
{
    /** Kompass driver_type => [our provider, our method]. */
    public const PROVIDER_MAP = [
        'sepa_offline' => ['offline', 'bank_transfer'],
        'free' => ['offline', 'complimentary'],
        'stripe' => [null, 'card'],
        'paypal' => [null, 'paypal'],
    ];

    /** Kompass billing cycle status => our coverage period status (+ whether a Payment is created). */
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
        $drivers = $this->db->table('payment_drivers')->get();
        $cycles = $this->db->table('subscription_billing_cycles')->get();

        return [
            'drivers' => $drivers->map(fn (object $d): array => [
                'id' => (int) $d->id,
                'name' => (string) $d->name,
                'type' => (string) $d->driver_type,
                'active' => (bool) $d->is_active,
                'deleted' => ($d->deleted_at ?? null) !== null,
                'provider' => self::PROVIDER_MAP[$d->driver_type][0] ?? null,
            ])->values()->all(),
            'duplicates_active' => $drivers->where('is_active', true)->whereNull('deleted_at')
                ->countBy('driver_type')->filter(fn (int $n): bool => $n > 1)->keys()->map(fn ($k): string => (string) $k)->values()->all(),
            'subscriptions' => $this->db->table('subscriptions')->count(),
            'subscriptions_without_payer' => $this->db->table('subscriptions')->whereNull('payer_user_id')->count(),
            'cycles' => $cycles->countBy('status')->map(fn (int $n): int => $n)->all(),
            'payments_to_create' => $cycles->filter(fn (object $c): bool => self::CYCLE_MAP[$c->status][1] ?? false)->count(),
            'plans_to_create' => $this->plans(),
            'currencies' => $this->db->table('subscriptions')->distinct()->orderBy('currency')->pluck('currency')->map(fn ($c): string => (string) $c)->all(),
            'overlapping_cycles' => $this->overlaps($cycles),
        ];
    }

    /**
     * Kompass has no plan table: one monthly plan per (amount, currency).
     *
     * @return list<string>
     */
    private function plans(): array
    {
        return $this->db->table('subscriptions')
            ->select(['amount_cents', 'currency'])
            ->distinct()
            ->orderBy('currency')->orderBy('amount_cents')
            ->get()
            ->map(fn (object $s): string => sprintf('%s %d / month', $s->currency, $s->amount_cents))
            ->all();
    }

    /**
     * Cycles of one subscription that overlap (e.g. PayPal calendar months
     * after SEPA anchor-day months): they need allowOverlap or trimming.
     *
     * @param  Collection<int, \stdClass>  $cycles
     */
    private function overlaps(Collection $cycles): int
    {
        return $cycles->groupBy('subscription_id')->sum(function (Collection $own): int {
            $sorted = $own->sortBy('period_start')->values();
            $count = 0;
            for ($i = 1; $i < $sorted->count(); $i++) {
                $count += (string) $sorted[$i]->period_start <= (string) $sorted[$i - 1]->period_end ? 1 : 0;
            }

            return $count;
        });
    }
}
