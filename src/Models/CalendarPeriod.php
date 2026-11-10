<?php

declare(strict_types=1);

namespace Ivanange\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $label
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 */
class CalendarPeriod extends Model
{
    protected $table = 'billing_calendar_periods';

    protected $fillable = ['calendar_id', 'label', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date'];
    }

    /**
     * @return BelongsTo<BillingCalendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(BillingCalendar::class, 'calendar_id');
    }
}
