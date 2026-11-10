<?php

declare(strict_types=1);

namespace Ivanange\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The share of a payment that pays for one coverage period. One payment can
 * pay many periods (several months, or a whole group of billables).
 */
class PaymentAllocation extends Model
{
    protected $table = 'billing_payment_allocations';

    protected $fillable = ['payment_id', 'coverage_period_id', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<CoveragePeriod, $this>
     */
    public function coveragePeriod(): BelongsTo
    {
        return $this->belongsTo(CoveragePeriod::class);
    }
}
