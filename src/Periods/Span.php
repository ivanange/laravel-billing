<?php

declare(strict_types=1);

namespace Ivanange\Billing\Periods;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One stretch of cover, both days inclusive.
 */
final readonly class Span
{
    public function __construct(
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
        public ?string $label = null,
    ) {
        if ($endsOn->lt($startsOn)) {
            throw new InvalidArgumentException('A period cannot end before it starts.');
        }
    }

    public function overlaps(CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return $this->startsOn->lte($end) && $this->endsOn->gte($start);
    }
}
