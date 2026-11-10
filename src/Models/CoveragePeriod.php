<?php

declare(strict_types=1);

namespace Ivanange\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Ivanange\Billing\Enums\PeriodStatus;

/**
 * Dates a billable is (or will be, once confirmed) covered for.
 *
 * @property int $id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property CarbonImmutable $grace_ends_on
 * @property string|null $label
 * @property int $amount
 * @property string $currency
 * @property PeriodStatus $status
 */
class CoveragePeriod extends Model
{
    protected $table = 'billing_coverage_periods';

    protected $fillable = [
        'subscription_id', 'billable_type', 'billable_id', 'starts_on', 'ends_on',
        'grace_ends_on', 'label', 'amount', 'currency', 'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'grace_ends_on' => 'immutable_date',
            'amount' => 'integer',
            'status' => PeriodStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @return BelongsToMany<Payment, $this>
     */
    public function payments(): BelongsToMany
    {
        return $this->belongsToMany(Payment::class, 'billing_payment_allocations')->withPivot('amount')->withTimestamps();
    }
}
