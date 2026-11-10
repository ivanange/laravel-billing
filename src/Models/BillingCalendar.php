<?php

declare(strict_types=1);

namespace Ivanange\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Named fixed periods, e.g. school terms or farming seasons.
 */
class BillingCalendar extends Model
{
    protected $table = 'billing_calendars';

    protected $fillable = ['name'];

    /**
     * @return HasMany<CalendarPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(CalendarPeriod::class, 'calendar_id')->orderBy('starts_on');
    }
}
