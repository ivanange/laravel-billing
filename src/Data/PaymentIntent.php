<?php

declare(strict_types=1);

namespace Ivanange\Billing\Data;

use InvalidArgumentException;

/**
 * Everything a provider needs to start one hosted checkout. Built from a
 * stored pending Payment, so the amount and currency can never come from
 * the payer's browser.
 */
final readonly class PaymentIntent
{
    /**
     * @param  int  $amount  minor units
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public string $reference,
        public int $amount,
        public string $currency,
        public string $method,
        public string $returnUrl,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $description = null,
        public array $metadata = [],
    ) {
        if ($amount <= 0) {
            throw new InvalidArgumentException('An online charge needs a positive amount.');
        }

        if (strlen($currency) !== 3) {
            throw new InvalidArgumentException('Currency must be a 3-letter ISO code.');
        }
    }
}
