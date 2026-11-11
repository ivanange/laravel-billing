<?php

declare(strict_types=1);

namespace Ivanange\Billing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Ivanange\Billing\Enums\DurationUnit;
use Ivanange\Billing\Periods\CalendarTerm;
use Ivanange\Billing\Periods\DurationTerm;
use Ivanange\Billing\Periods\Term;
use LogicException;

/**
 * A price preset, e.g. "1 month – GHS 5" or "Season – GHS 20".
 *
 * @property int $id
 * @property int $plan_id
 * @property string $label
 * @property DurationUnit|null $duration_unit
 * @property int|null $duration_count
 * @property int|null $calendar_id
 * @property int $amount minor units, in the plan's currency
 */
class PlanPrice extends Model
{
    protected $table = 'billing_plan_prices';

    protected $fillable = ['plan_id', 'label', 'duration_unit', 'duration_count', 'calendar_id', 'amount', 'sort'];

    protected function casts(): array
    {
        return [
            'duration_unit' => DurationUnit::class,
            'duration_count' => 'integer',
            'amount' => 'integer',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<BillingCalendar, $this>
     */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(BillingCalendar::class, 'calendar_id');
    }

    /**
     * Duration presets map to a term directly. Calendar presets need the
     * chosen periods, so callers build a CalendarTerm themselves.
     */
    public function durationTerm(): Term
    {
        if ($this->duration_unit === null) {
            throw new LogicException("Price {$this->id} is a calendar price; pick its periods.");
        }

        return new DurationTerm($this->duration_unit, max(1, (int) $this->duration_count));
    }

    public function isCalendar(): bool
    {
        return $this->calendar_id !== null;
    }

    /**
     * @param  list<int>  $periodIds
     */
    public function calendarTerm(array $periodIds): CalendarTerm
    {
        return new CalendarTerm(
            CalendarPeriod::query()->where('calendar_id', $this->calendar_id)->whereKey($periodIds)->get(),
        );
    }
}
