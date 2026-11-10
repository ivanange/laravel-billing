<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways;

/**
 * A provider setting described as plain data, so the core never depends on
 * Filament; the Filament plugin turns these into form fields.
 */
final readonly class SettingField
{
    public const TEXT = 'text';

    public const TEXTAREA = 'textarea';

    public const SECRET = 'secret';

    public const TOGGLE = 'toggle';

    public function __construct(
        public string $name,
        public string $type,
        public string $labelKey,
        public bool $required = false,
        public mixed $default = null,
        public ?string $helpKey = null,
    ) {}

    public function label(): string
    {
        return __($this->labelKey);
    }

    public function help(): ?string
    {
        return $this->helpKey === null ? null : __($this->helpKey);
    }
}
