<?php

declare(strict_types=1);

namespace Ivanange\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Enums\SubscriptionStatus;

/**
 * One billable on one plan. paid_until is a cached summary; the source of
 * truth is the paid coverage periods.
 *
 * @property int $id
 * @property SubscriptionStatus $status
 * @property CarbonImmutable|null $paid_until
 * @property CarbonImmutable|null $expiry_notified_for
 * @property CarbonImmutable|null $lapse_notified_for
 * @property Plan $plan
 * @property Model $billable
 */
class Subscription extends Model
{
    protected $table = 'billing_subscriptions';

    protected $fillable = ['plan_id', 'billable_type', 'billable_id', 'payer_type', 'payer_id', 'gateway_instance_id', 'status'];

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'paid_until' => 'immutable_date',
            'expiry_notified_for' => 'immutable_date',
            'lapse_notified_for' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class)->withTrashed();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function payer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<GatewayInstance, $this>
     */
    public function gatewayInstance(): BelongsTo
    {
        return $this->belongsTo(GatewayInstance::class)->withTrashed();
    }

    /**
     * @return HasMany<CoveragePeriod, $this>
     */
    public function coveragePeriods(): HasMany
    {
        return $this->hasMany(CoveragePeriod::class)->orderBy('starts_on');
    }

    /**
     * Last day of the latest period that reserves dates (paid or awaiting
     * confirmation), which is where the next period starts by default.
     */
    public function lastReservedDay(): ?CarbonImmutable
    {
        $last = $this->coveragePeriods()->getQuery()
            ->whereIn('status', PeriodStatus::occupying())
            ->max('ends_on');

        return $last === null ? null : CarbonImmutable::parse($last)->startOfDay();
    }

    public function graceEndsOn(): ?CarbonImmutable
    {
        return $this->paid_until?->addDays((int) $this->plan->grace_days);
    }
}
