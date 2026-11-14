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

    /** Class of the underlying error. The error itself is not chained: its message could quote a request. */
    public ?string $previousClass = null;

    /**
     * Strip anything that looks like a key or bearer token and cap the length.
     */
    public static function redact(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $clean = (string) preg_replace(['/\b(sk|pk|hsk)_(live|test)_[A-Za-z0-9_]+/', '/Bearer\s+\S+/i', '/Basic\s+[A-Za-z0-9+\/=]+/i'], '[redacted]', $message);

        return mb_substr($clean, 0, 300);
    }

    public static function from(?string $providerMessage, ?\Throwable $previous = null): self
    {
        $error = new self(__('billing::billing.errors.gateway_error'));
        $error->providerMessage = self::redact($providerMessage);
        $error->previousClass = $previous === null ? null : $previous::class;

        return $error;
    }
}
