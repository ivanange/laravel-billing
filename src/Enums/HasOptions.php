<?php

declare(strict_types=1);

namespace Ivanange\Billing\Enums;

/**
 * Translated labels for backed enums: billing::billing.enums.<key>.<value>.
 */
trait HasOptions
{
    abstract protected static function translationKey(): string;

    public function label(): string
    {
        return __('billing::billing.enums.'.static::translationKey().'.'.$this->value);
    }

    /**
     * @return array<string, string> value => label
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }
}
