<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ivanange\Billing\Concerns\HasBilling;
use Ivanange\Billing\Contracts\Billable;

/** A uuid-keyed billable that belongs to a Company (the tenant in tenancy tests). */
class Guardian extends Model implements Billable
{
    use HasBilling;
    use HasUuids;

    protected $table = 'guardians';

    protected $guarded = [];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
