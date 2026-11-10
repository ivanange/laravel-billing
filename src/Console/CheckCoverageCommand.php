<?php

declare(strict_types=1);

namespace Ivanange\Billing\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Ivanange\Billing\Enums\SubscriptionStatus;
use Ivanange\Billing\Events\CoverageExpiring;
use Ivanange\Billing\Events\CoverageLapsed;
use Ivanange\Billing\Models\Subscription;

/**
 * Daily: announce cover that is about to end and cover that just ended.
 * Each event fires once per paid_until value, so running the command twice
 * (or after a missed day) never sends duplicate reminders.
 */
class CheckCoverageCommand extends Command
{
    /** Lapses older than this are ignored, so a first run doesn't message everyone who ever lapsed. */
    private const LAPSE_LOOKBACK_DAYS = 7;

    protected $signature = 'billing:check-coverage {--date= : Pretend today is this date (Y-m-d)}';

    protected $description = 'Fire CoverageExpiring / CoverageLapsed events for subscriptions';

    public function handle(): int
    {
        $today = CarbonImmutable::parse($this->option('date') ?? 'today')->startOfDay();
        $daysBefore = max(0, (int) config('billing.reminders.days_before', 3));

        $expiring = $this->expiring($today, $daysBefore);
        $lapsed = $this->lapsed($today);

        $this->info("Expiring: {$expiring}, lapsed: {$lapsed}.");

        return self::SUCCESS;
    }

    private function expiring(CarbonImmutable $today, int $daysBefore): int
    {
        $count = 0;

        Subscription::query()
            ->with('plan')
            ->where('status', SubscriptionStatus::Active->value)
            ->where('paid_until', '>=', $today)
            ->where('paid_until', '<=', $today->addDays($daysBefore))
            ->chunkById(200, function ($subscriptions) use (&$count): void {
                foreach ($subscriptions as $subscription) {
                    if ($subscription->expiry_notified_for?->equalTo($subscription->paid_until)) {
                        continue;
                    }

                    $subscription->forceFill(['expiry_notified_for' => $subscription->paid_until])->save();
                    event(new CoverageExpiring($subscription));
                    $count++;
                }
            });

        return $count;
    }

    private function lapsed(CarbonImmutable $today): int
    {
        $count = 0;

        Subscription::query()
            ->with('plan')
            ->where('status', SubscriptionStatus::Active->value)
            ->where('paid_until', '<', $today)
            // Bounded window (grace up to a year) so old history isn't scanned daily.
            ->where('paid_until', '>=', $today->subDays(self::LAPSE_LOOKBACK_DAYS + 366))
            ->chunkById(200, function ($subscriptions) use ($today, &$count): void {
                foreach ($subscriptions as $subscription) {
                    $graceEnd = $subscription->graceEndsOn();
                    $justLapsed = $graceEnd !== null && $graceEnd->lt($today)
                        && $graceEnd->gte($today->subDays(self::LAPSE_LOOKBACK_DAYS));

                    if (! $justLapsed || $subscription->lapse_notified_for?->equalTo($subscription->paid_until)) {
                        continue;
                    }

                    $subscription->forceFill(['lapse_notified_for' => $subscription->paid_until])->save();
                    event(new CoverageLapsed($subscription));
                    $count++;
                }
            });

        return $count;
    }
}
