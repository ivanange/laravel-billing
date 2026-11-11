<?php

declare(strict_types=1);

namespace Ivanange\Billing\Exceptions;

/**
 * The provider refused or could not be reached. The message is translated
 * and safe for payers; the provider's own message is kept separately for
 * logs (it never contains credentials).
 */
class GatewayError extends BillingException
{
    public ?string $providerMessage = null;

    public static function from(?string $providerMessage, ?\Throwable $previous = null): self
    {
        $error = new self(__('billing::billing.errors.gateway_error'), 0, $previous);
        $error->providerMessage = $providerMessage;

        return $error;
    }
}
