<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Unit;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Ivanange\Billing\Enums\DurationUnit;
use Ivanange\Billing\Periods\RangeTerm;
use Ivanange\Billing\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyAndPeriodsTest extends TestCase
{
    public function test_minor_units_follow_the_currency(): void
    {
        $this->assertSame(1050, Money::toMinor('10.50', 'GHS'));
        $this->assertSame(2000, Money::toMinor(2000, 'XAF'));
        $this->assertSame(1234, Money::toMinor('1.234', 'KWD'));
        $this->assertSame(10.5, Money::toMajor(1050, 'ghs'));
        $this->assertSame([3, 3, 4], Money::split(10, 3));
        $this->assertSame([], Money::split(10, 0));
    }

    public function test_negative_or_non_numeric_amounts_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toMinor('-1', 'GHS');
    }

    public function test_duration_ends_are_inclusive_and_never_overflow(): void
    {
        $start = CarbonImmutable::parse('2026-01-31');

        $this->assertSame('2026-02-27', DurationUnit::Month->endFrom($start, 1)->toDateString());
        $this->assertSame('2026-04-29', DurationUnit::Quarter->endFrom($start, 1)->toDateString());
        $this->assertSame('2027-01-30', DurationUnit::Year->endFrom($start, 1)->toDateString());
        $this->assertSame('2026-02-06', DurationUnit::Week->endFrom($start, 1)->toDateString());
        $this->assertSame('2026-01-31', DurationUnit::Day->endFrom($start, 1)->toDateString());
    }

    public function test_a_range_cannot_end_before_it_starts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RangeTerm(CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-04-01'));
    }
}
