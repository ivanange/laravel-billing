<?php

declare(strict_types=1);

namespace Ivanange\Billing\Periods;

use Carbon\CarbonImmutable;

/**
 * Explicit dates, e.g. "the 2027 season: 1 Apr – 31 Oct".
 */
final readonly class RangeTerm implements Term
{
    private Span $span;

    public function __construct(CarbonImmutable $startsOn, CarbonImmutable $endsOn, ?string $label = null)
    {
        $this->span = new Span($startsOn->startOfDay(), $endsOn->startOfDay(), $label);
    }

    public function spans(CarbonImmutable $defaultStart): array
    {
        return [$this->span];
    }

    public function describe(): string
    {
        return $this->span->label
            ?? $this->span->startsOn->toDateString().' – '.$this->span->endsOn->toDateString();
    }
}
