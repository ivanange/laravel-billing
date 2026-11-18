<?php

declare(strict_types=1);

namespace Ivanange\Billing\Enums;

/**
 * Why an online payment ended "failed" (payments.failure_reason). The column
 * stays a plain string so a value this version does not know never breaks
 * reading a payment: use Payment::failureReason() to get the case.
 */
enum FailureReason: string
{
    use HasOptions;

    /** The provider refused to start the checkout. */
    case StartFailed = 'start_failed';

    case Declined = 'declined';

    /** The provider reported another amount or currency. */
    case AmountMismatch = 'amount_mismatch';

    /** The provider said "not paid" until the abandon window ended. */
    case Abandoned = 'abandoned';

    /**
     * The last check after the abandon window errored or its answer did not
     * name the payment: it may really have been paid.
     */
    case Unverifiable = 'unverifiable';

    /**
     * Failed payments a later verified success may still confirm, so the
     * return page keeps asking the provider about them for a while.
     *
     * @return list<self>
     */
    public static function mayStillSucceed(): array
    {
        return [self::Declined, self::Abandoned, self::Unverifiable];
    }

    protected static function translationKey(): string
    {
        return 'failure_reason';
    }
}
