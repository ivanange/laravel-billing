<?php

declare(strict_types=1);

namespace Ivanange\Billing\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\Plan;
use Ivanange\Billing\Models\Subscription;

/**
 * Implements Ivanange\Billing\Contracts\Billable on an Eloquent model.
 *
 * Covered on a date = a PAID period contains the date, counting the plan's
 * grace days after it ends. Unconfirmed payments give no cover.
 */
trait HasBilling
{
    public function subscriptions(): MorphMany
    {
        return $this->morphMany(Subscription::class, 'billable');
    }

    public function coveragePeriods(): MorphMany
    {
        return $this->morphMany(CoveragePeriod::class, 'billable');
    }

    public function isCovered(?DateTimeInterface $on = null, ?Plan $plan = null): bool
    {
        $query = $this->coveragePeriods()->getQuery();
        static::constrainToCovering($query, $on, $plan);

        return $query->exists();
    }

    /**
     * Last paid day (grace not included), or null if never paid.
     */
    public function paidUntil(?Plan $plan = null): ?CarbonImmutable
    {
        $query = $this->coveragePeriods()->getQuery()->where('status', PeriodStatus::Paid->value);

        if ($plan !== null) {
            $query->whereIn('subscription_id', Subscription::query()->select('id')->where('plan_id', $plan->getKey()));
        }

        $last = $query->max('ends_on');

        return $last === null ? null : CarbonImmutable::parse($last)->startOfDay();
    }

    public function billingName(): string
    {
        $name = $this->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : class_basename($this).' #'.$this->getKey();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCovered(Builder $query, ?DateTimeInterface $on = null, ?Plan $plan = null): Builder
    {
        return $query->whereHas('coveragePeriods', fn (Builder $periods) => static::constrainToCovering($periods, $on, $plan));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotCovered(Builder $query, ?DateTimeInterface $on = null, ?Plan $plan = null): Builder
    {
        return $query->whereDoesntHave('coveragePeriods', fn (Builder $periods) => static::constrainToCovering($periods, $on, $plan));
    }

    /**
     * Dates are compared as start-of-day timestamps so the query behaves the
     * same on SQLite (dates stored as text with a time) and MySQL/Postgres.
     */
    public static function constrainToCovering(Builder $periods, ?DateTimeInterface $on, ?Plan $plan): void
    {
        $day = CarbonImmutable::instance($on ?? now())->startOfDay();

        $periods->where('status', PeriodStatus::Paid->value)
            ->where('starts_on', '<=', $day)
            ->where('grace_ends_on', '>=', $day);

        if ($plan !== null) {
            $periods->whereIn('subscription_id', Subscription::query()->select('id')->where('plan_id', $plan->getKey()));
        }
    }
}
