<?php

declare(strict_types=1);

namespace Ivanange\Billing\Enums;

enum PaymentStatus: string
{
    use HasOptions;

    /** Recorded by a person, waiting for confirmation (maker-checker). */
    case Recorded = 'recorded';
    case Confirmed = 'confirmed';
    case Void = 'void';

    protected static function translationKey(): string
    {
        return 'payment_status';
    }
}
