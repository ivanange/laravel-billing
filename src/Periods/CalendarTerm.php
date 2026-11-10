<?php

declare(strict_types=1);

namespace Ivanange\Billing\Periods;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Ivanange\Billing\Models\CalendarPeriod;

/**
 * Named periods from a billing calendar, e.g. school terms or seasons.
 */
final readonly class CalendarTerm implements Term
{
    /** @var list<Span> */
    private array $spans;

    /**
     * @param  iterable<CalendarPeriod>  $periods
     */
    public function __construct(iterable $periods)
    {
        $spans = [];
        foreach ($periods as $period) {
            $spans[] = new Span(
                CarbonImmutable::parse($period->starts_on)->startOfDay(),
                CarbonImmutable::parse($period->ends_on)->startOfDay(),
                $period->label,
            );
        }

        if ($spans === []) {
            throw new InvalidArgumentException('Choose at least one calendar period.');
        }

        usort($spans, fn (Span $a, Span $b): int => $a->startsOn <=> $b->startsOn);
        $this->spans = $spans;
    }

    /**
     * @param  list<int>  $ids
     */
    public static function fromIds(array $ids): self
    {
        return new self(CalendarPeriod::query()->whereKey($ids)->get());
    }

    public function spans(CarbonImmutable $defaultStart): array
    {
        return $this->spans;
    }

    public function describe(): string
    {
        return implode(', ', array_map(fn (Span $span): string => (string) $span->label, $this->spans));
    }
}
