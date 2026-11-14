<?php

declare(strict_types=1);

namespace Ivanange\Billing\Exceptions;

use DateTimeInterface;
use RuntimeException;

/**
 * Every rule the billing services enforce fails with this exception. The
 * message is translated and safe to show to staff.
 */
class BillingException extends RuntimeException
{
    /**
     * @param  array<string, string|int|float|null>  $replace
     */
    public static function because(string $key, array $replace = []): self
    {
        return new self(__('billing::billing.errors.'.$key, $replace));
    }

    public static function overlap(string $billable, DateTimeInterface $start, DateTimeInterface $end): self
    {
        return self::because('overlap', [
            'billable' => $billable,
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ]);
    }
}
