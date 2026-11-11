<?php

declare(strict_types=1);

namespace Ivanange\Billing\Support;

use Illuminate\Database\Eloquent\Model;
use Ivanange\Billing\Contracts\Billable;

/**
 * Display name of a morph-loaded billable, which may have been deleted.
 */
final class BillableName
{
    public static function of(?Model $model): string
    {
        return $model instanceof Billable ? $model->billingName() : '—';
    }
}
