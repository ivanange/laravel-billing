<?php

declare(strict_types=1);

namespace Ivanange\Billing\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Support\Money;

/**
 * Money received (or claimed received) through one gateway instance and
 * method. Never deleted: wrong entries are voided with a reason.
 *
 * @property int $id
 * @property int $gateway_instance_id
 * @property string $method
 * @property int $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property CarbonImmutable $paid_on
 * @property string|null $reference
 * @property string|null $proof_path
 * @property int|null $recorded_by
 * @property int|null $confirmed_by
 * @property string|null $external_id
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $meta
 * @property GatewayInstance $gatewayInstance
 */
class Payment extends Model
{
    protected $table = 'billing_payments';

    protected $fillable = [
        'payer_type', 'payer_id', 'gateway_instance_id', 'method', 'amount', 'currency', 'status',
        'paid_on', 'reference', 'external_id', 'proof_path', 'notes', 'recorded_by', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => PaymentStatus::class,
            'paid_on' => 'immutable_date',
            'confirmed_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'refunded_at' => 'immutable_datetime',
            'meta' => 'array',
        ];
    }

    public function payer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<GatewayInstance, $this>
     */
    public function gatewayInstance(): BelongsTo
    {
        return $this->belongsTo(GatewayInstance::class)->withTrashed();
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @return BelongsToMany<CoveragePeriod, $this>
     */
    public function coveragePeriods(): BelongsToMany
    {
        return $this->belongsToMany(CoveragePeriod::class, 'billing_payment_allocations')->withPivot('amount')->withTimestamps();
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(self::userModel(), 'recorded_by');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(self::userModel(), 'confirmed_by');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function voider(): BelongsTo
    {
        return $this->belongsTo(self::userModel(), 'voided_by');
    }

    /**
     * Names of everyone this payment covers.
     *
     * @return list<string>
     */
    public function beneficiaryNames(): array
    {
        return $this->coveragePeriods
            ->map(fn (CoveragePeriod $period): string => $period->billable?->billingName() ?? '—')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return class-string<Model>
     */
    private static function userModel(): string
    {
        return (string) config('billing.user_model', config('auth.providers.users.model'));
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function refunder(): BelongsTo
    {
        return $this->belongsTo(self::userModel(), 'refunded_by');
    }

    public function isOnline(): bool
    {
        return data_get($this->meta, 'checkout') !== null;
    }

    public function formattedAmount(): string
    {
        return Money::format($this->amount, $this->currency);
    }

    public function methodLabel(): string
    {
        return $this->gatewayInstance?->methodLabel($this->method) ?? $this->method;
    }
}
