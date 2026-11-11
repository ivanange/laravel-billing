<?php

declare(strict_types=1);

namespace Ivanange\Billing\Enums;

/**
 * What a provider says about an online charge.
 */
enum ChargeStatus: string
{
    case Succeeded = 'succeeded';
    case Pending = 'pending';
    case Failed = 'failed';
}
