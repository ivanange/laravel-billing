<?php

declare(strict_types=1);

namespace Ivanange\Billing\Contracts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\Plan;
use Ivanange\Billing\Models\Subscription;

/**
 * Anything that can be paid for: a farmer, a student, a company seat...
 * Implement on an Eloquent model and use the HasBilling trait.
 */
interface Billable
{
    /**
     * @return MorphMany<Subscription, covariant Model>
     */
    public function subscriptions(): MorphMany;

    /**
     * @return MorphMany<CoveragePeriod, covariant Model>
     */
    public function coveragePeriods(): MorphMany;

    public function isCovered(?DateTimeInterface $on = null, ?Plan $plan = null): bool;

    /** Last paid day (grace not included), or null if never paid. */
    public function paidUntil(?Plan $plan = null): ?CarbonImmutable;

    /** Name shown on payments, receipts and admin screens. */
    public function billingName(): string;
}
