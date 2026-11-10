<?php

declare(strict_types=1);

namespace Ivanange\Billing\Enums;

enum SubscriptionStatus: string
{
    use HasOptions;

    case Active = 'active';
    case Cancelled = 'cancelled';

    protected static function translationKey(): string
    {
        return 'subscription_status';
    }
}
