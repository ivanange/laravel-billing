<?php

declare(strict_types=1);

namespace Ivanange\Billing\Data;

/**
 * A checkout the provider accepted: send the payer to $redirectUrl.
 */
final readonly class StartedCharge
{
    public function __construct(
        public string $redirectUrl,
        public string $reference,
        public ?string $externalId = null,
    ) {}
}
