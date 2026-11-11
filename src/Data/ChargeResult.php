<?php

declare(strict_types=1);

namespace Ivanange\Billing\Data;

use Ivanange\Billing\Enums\ChargeStatus;

/**
 * A provider's answer about one charge (from a verify call or a webhook).
 * Amount and currency are what the provider says was paid; the settler
 * compares them with the stored payment before giving any cover.
 */
final readonly class ChargeResult
{
    /**
     * @param  int|null  $amount  minor units
     */
    public function __construct(
        public ChargeStatus $status,
        public string $reference,
        public ?int $amount = null,
        public ?string $currency = null,
        public ?string $externalId = null,
        public ?string $message = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === ChargeStatus::Succeeded;
    }

    public function failed(): bool
    {
        return $this->status === ChargeStatus::Failed;
    }
}
