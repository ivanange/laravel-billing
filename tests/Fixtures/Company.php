<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Ivanange\Billing\Concerns\HasBilling;
use Ivanange\Billing\Contracts\Billable;

class Company extends Model implements Billable
{
    use HasBilling;

    protected $table = 'companies';

    protected $guarded = [];
}
