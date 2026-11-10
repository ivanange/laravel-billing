<?php

declare(strict_types=1);

namespace Ivanange\Billing\Periods;

use Carbon\CarbonImmutable;

/**
 * What a payment buys: a duration, a fixed date range or calendar periods.
 */
interface Term
{
    /**
     * @param  CarbonImmutable  $defaultStart  used by terms without their own dates
     * @return list<Span>
     */
    public function spans(CarbonImmutable $defaultStart): array;

    /** Human description, e.g. "3 months" or "Term 2". */
    public function describe(): string;
}
