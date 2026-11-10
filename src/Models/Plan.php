<?php

declare(strict_types=1);

namespace Ivanange\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Ivanange\Billing\Services\CoverageLedger;

/**
 * What is being paid for, e.g. "Weekly forecast SMS" or "Tuition".
 *
 * @property int $id
 * @property string $name
 * @property string $currency
 * @property int $grace_days
 * @property string|null $billable_type
 * @property bool $is_active
 */
class Plan extends Model
{
    use SoftDeletes;

    protected $table = 'billing_plans';

    protected $fillable = ['name', 'description', 'currency', 'grace_days', 'billable_type', 'is_active'];

    /** Mirror the column defaults so a just-created plan is usable without a refresh. */
    protected $attributes = ['grace_days' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['grace_days' => 'integer', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $plan) => $plan->currency = strtoupper((string) $plan->currency));

        // Grace is stored on each period so the covered() query stays a plain
        // indexed date comparison; keep it in step when the plan changes.
        static::updated(function (self $plan): void {
            if ($plan->wasChanged('grace_days')) {
                app(CoverageLedger::class)->applyGrace($plan);
            }
        });
    }

    /**
     * @return HasMany<PlanPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class)->orderBy('sort');
    }

    /**
     * @return HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function accepts(Model $billable): bool
    {
        return $this->billable_type === null || $this->billable_type === $billable->getMorphClass();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFor(Builder $query, Model $billable): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->whereNull('billable_type')
            ->orWhere('billable_type', $billable->getMorphClass()));
    }
}
