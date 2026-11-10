<?php

declare(strict_types=1);

namespace Ivanange\Billing\Periods;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Ivanange\Billing\Enums\DurationUnit;

final readonly class DurationTerm implements Term
{
    public function __construct(
        public DurationUnit $unit,
        public int $count = 1,
    ) {
        if ($count < 1) {
            throw new InvalidArgumentException('Duration must be at least 1.');
        }
    }

    public function spans(CarbonImmutable $defaultStart): array
    {
        $start = $defaultStart->startOfDay();

        return [new Span($start, $this->unit->endFrom($start, $this->count), $this->describe())];
    }

    public function describe(): string
    {
        return $this->unit->describe($this->count);
    }
}
