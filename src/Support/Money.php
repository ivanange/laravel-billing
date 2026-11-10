<?php

declare(strict_types=1);

namespace Ivanange\Billing\Support;

use InvalidArgumentException;
use NumberFormatter;

/**
 * Amounts are stored as integer minor units (pesewas, cents; whole francs
 * for XAF). This converts to and from what people type and read.
 */
final class Money
{
    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    private const THREE_DECIMAL = ['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND'];

    public static function decimals(string $currency): int
    {
        $currency = strtoupper($currency);

        return match (true) {
            in_array($currency, self::ZERO_DECIMAL, true) => 0,
            in_array($currency, self::THREE_DECIMAL, true) => 3,
            default => 2,
        };
    }

    public static function toMinor(string|int|float $major, string $currency): int
    {
        if (! is_numeric($major)) {
            throw new InvalidArgumentException('Amount must be numeric.');
        }

        $minor = (int) round(((float) $major) * (10 ** self::decimals($currency)));

        if ($minor < 0) {
            throw new InvalidArgumentException('Amount cannot be negative.');
        }

        return $minor;
    }

    public static function toMajor(int $minor, string $currency): float
    {
        return $minor / (10 ** self::decimals($currency));
    }

    public static function format(int $minor, string $currency, ?string $locale = null): string
    {
        $formatter = new NumberFormatter($locale ?? app()->getLocale(), NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::FRACTION_DIGITS, self::decimals($currency));

        return (string) $formatter->formatCurrency(self::toMajor($minor, $currency), strtoupper($currency));
    }

    /**
     * Split $total into $parts integer shares; the remainder goes to the last.
     *
     * @return list<int>
     */
    public static function split(int $total, int $parts): array
    {
        if ($parts < 1) {
            return [];
        }

        $share = intdiv($total, $parts);
        $shares = array_fill(0, $parts, $share);
        $shares[$parts - 1] += $total - ($share * $parts);

        return $shares;
    }
}
