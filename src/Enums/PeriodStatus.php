<?php

declare(strict_types=1);

namespace Ivanange\Billing\Enums;

enum PeriodStatus: string
{
    use HasOptions;

    /** Its payment is not confirmed yet: reserves the dates but gives no cover. */
    case Pending = 'pending';
    case Paid = 'paid';
    case Void = 'void';

    protected static function translationKey(): string
    {
        return 'period_status';
    }

    /**
     * Statuses that occupy dates (used for overlap checks and default start).
     *
     * @return list<string>
     */
    public static function occupying(): array
    {
        return [self::Pending->value, self::Paid->value];
    }
}
