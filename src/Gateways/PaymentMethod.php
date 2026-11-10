<?php

declare(strict_types=1);

namespace Ivanange\Billing\Gateways;

final readonly class PaymentMethod
{
    public function __construct(
        public string $key,
        public string $labelKey,
    ) {}

    public function label(): string
    {
        return __($this->labelKey);
    }
}
