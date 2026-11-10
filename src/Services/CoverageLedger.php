<?php

declare(strict_types=1);

namespace Ivanange\Billing\Services;

use Carbon\CarbonImmutable;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\Plan;
use Ivanange\Billing\Models\Subscription;

/**
 * The one place derived coverage values are recomputed.
 */
class CoverageLedger
{
    public function refresh(Subscription $subscription): Subscription
    {
        $last = $subscription->coveragePeriods()->getQuery()
            ->where('status', PeriodStatus::Paid->value)
            ->max('ends_on');

        $subscription->forceFill(['paid_until' => $last === null ? null : CarbonImmutable::parse($last)])->save();

        return $subscription;
    }

    public function graceEnd(Plan $plan, CarbonImmutable $endsOn): CarbonImmutable
    {
        return $endsOn->addDays(max(0, (int) $plan->grace_days));
    }

    public function applyGrace(Plan $plan): void
    {
        CoveragePeriod::query()
            ->whereIn('subscription_id', Subscription::query()->select('id')->where('plan_id', $plan->getKey()))
            ->chunkById(500, function ($periods) use ($plan): void {
                foreach ($periods as $period) {
                    $period->forceFill(['grace_ends_on' => $this->graceEnd($plan, $period->ends_on)])->save();
                }
            });
    }
}
