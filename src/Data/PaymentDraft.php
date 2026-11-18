<?php

declare(strict_types=1);

namespace Ivanange\Billing\Data;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Ivanange\Billing\Contracts\Billable;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Plan;
use Ivanange\Billing\Periods\Term;

/**
 * Everything needed to record one payment. With several billables it is a
 * group payment: each billable gets the same term and $amountPerBillable.
 */
final readonly class PaymentDraft
{
    /**
     * @param  list<Model&Billable>  $billables
     * @param  int  $amountPerBillable  minor units in the plan's currency
     * @param  CarbonImmutable|null  $startsOn  null = continue after existing cover (or today if it lapsed)
     * @param  Model|null  $payer  null = each billable pays for itself; the payment of a group then names no payer
     */
    public function __construct(
        public array $billables,
        public Plan $plan,
        public Term $term,
        public GatewayInstance $gateway,
        public string $method,
        public int $amountPerBillable,
        public ?CarbonImmutable $startsOn = null,
        public ?Model $payer = null,
        public ?CarbonImmutable $paidOn = null,
        public ?string $reference = null,
        public ?string $proofPath = null,
        public ?string $notes = null,
        public bool $allowOverlap = false,
    ) {}

    public function total(): int
    {
        return $this->amountPerBillable * count($this->billables);
    }

    /** Who the payment came from; null for a group nobody was named for. */
    public function payer(): ?Model
    {
        return $this->payer ?? (count($this->billables) === 1 ? $this->billables[0] : null);
    }

    /** Who pays for a new subscription of $billable (design §3: defaults to the billable). */
    public function subscriptionPayer(Model&Billable $billable): Model
    {
        return $this->payer ?? $billable;
    }
}
