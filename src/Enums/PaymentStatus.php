<?php

declare(strict_types=1);

namespace Ivanange\Billing\Enums;

enum PaymentStatus: string
{
    use HasOptions;

    /** Recorded by a person, waiting for confirmation (maker-checker). */
    case Recorded = 'recorded';

    /** Started online (hosted checkout); waiting for the provider. No cover yet. */
    case Pending = 'pending';
    case Confirmed = 'confirmed';

    /** Declined, abandoned, or the provider reported another amount/currency. No cover. */
    case Failed = 'failed';

    /** Money returned through the provider; its cover is void. */
    case Refunded = 'refunded';
    case Void = 'void';

    /**
     * Final states a provider callback can no longer change.
     *
     * @return list<self>
     */
    public static function settled(): array
    {
        return [self::Confirmed, self::Refunded, self::Void];
    }

    protected static function translationKey(): string
    {
        return 'payment_status';
    }
}
