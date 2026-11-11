<?php

declare(strict_types=1);

namespace Ivanange\Billing\Exceptions;

use RuntimeException;

/**
 * A webhook whose signature or source could not be verified. The message
 * never contains the payload or any secret.
 */
class InvalidWebhook extends RuntimeException
{
    public static function signature(): self
    {
        return new self('Invalid webhook signature.');
    }

    public static function payload(): self
    {
        return new self('Unreadable webhook payload.');
    }
}
