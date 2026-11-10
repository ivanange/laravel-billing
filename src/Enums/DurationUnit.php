<?php

declare(strict_types=1);

namespace Ivanange\Billing\Enums;

use Carbon\CarbonImmutable;

enum DurationUnit: string
{
    use HasOptions;

    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Quarter = 'quarter';
    case Year = 'year';

    protected static function translationKey(): string
    {
        return 'duration_unit';
    }

    /**
     * Last covered day (inclusive) of $count units starting on $start.
     * Months never overflow: cover starting 31 Jan for 1 month ends 27/28 Feb,
     * so the next period starts on the last day of February.
     */
    public function endFrom(CarbonImmutable $start, int $count): CarbonImmutable
    {
        $next = match ($this) {
            self::Day => $start->addDays($count),
            self::Week => $start->addWeeks($count),
            self::Month => $start->addMonthsNoOverflow($count),
            self::Quarter => $start->addMonthsNoOverflow($count * 3),
            self::Year => $start->addYearsNoOverflow($count),
        };

        return $next->subDay();
    }

    public function describe(int $count): string
    {
        return trans_choice('billing::billing.enums.duration_count.'.$this->value, $count, ['count' => $count]);
    }
}
