<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Forms;

use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Ivanange\Billing\Contracts\Billable;
use Ivanange\Billing\Data\PaymentDraft;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Gateways\GatewayCatalog;
use Ivanange\Billing\Gateways\Providers\OfflineProvider;
use Ivanange\Billing\Models\CalendarPeriod;
use Ivanange\Billing\Models\GatewayInstance;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Models\Plan;
use Ivanange\Billing\Models\PlanPrice;
use Ivanange\Billing\Periods\RangeTerm;
use Ivanange\Billing\Periods\Term;
use Ivanange\Billing\Services\PaymentRecorder;
use Ivanange\Billing\Support\Money;

/**
 * The "record a payment" form shared by the single and group actions, and
 * the code that turns its data into a PaymentDraft.
 */
final class RecordPaymentForm
{
    public const CUSTOM = 'custom';

    /**
     * @return list<Component>
     */
    public static function components(?Model $billable, bool $group): array
    {
        return [...self::whatFields($billable, $group), ...self::howFields()];
    }

    /**
     * @param  list<Model&Billable>  $billables
     */
    public static function handle(array $data, array $billables, Action $action): void
    {
        Gate::authorize('billing.record');

        try {
            $payment = app(PaymentRecorder::class)->record(self::draft($data, $billables), auth()->user());
        } catch (BillingException|InvalidArgumentException $e) {
            // The uploaded proof is kept: the modal stays open and a retry reuses it.
            Notification::make()->danger()->title(__('billing::billing.ui.record.failed'))->body($e->getMessage())->send();
            $action->halt();

            return;
        }

        $confirmed = $payment->status === PaymentStatus::Confirmed;
        $until = count($billables) === 1 ? $billables[0]->paidUntil($payment->coveragePeriods->first()?->subscription?->plan) : null;

        Notification::make()
            ->success()
            ->title($confirmed ? __('billing::billing.ui.record.done') : __('billing::billing.ui.record.awaiting'))
            ->body(implode(' ', array_filter([
                $payment->formattedAmount(),
                $confirmed && $until ? __('billing::billing.ui.record.covered_until', ['date' => $until->isoFormat('LL')]) : null,
            ])))
            ->send();
    }

    /**
     * @param  list<Model&Billable>  $billables
     */
    public static function draft(array $data, array $billables): PaymentDraft
    {
        $plan = Plan::query()->findOrFail($data['plan_id'] ?? null);
        $gateway = GatewayInstance::query()->findOrFail($data['gateway_instance_id'] ?? null);

        return new PaymentDraft(
            billables: $billables,
            plan: $plan,
            term: self::term($data, $plan),
            gateway: $gateway,
            method: (string) ($data['method'] ?? ''),
            amountPerBillable: Money::toMinor((string) ($data['amount'] ?? ''), $plan->currency),
            startsOn: self::date($data['starts_on'] ?? null),
            paidOn: self::date($data['paid_on'] ?? null),
            reference: $data['reference'] ?? null,
            proofPath: self::proofPath($data['proof'] ?? null),
            notes: $data['notes'] ?? null,
            allowOverlap: (bool) ($data['allow_overlap'] ?? false),
        );
    }

    private static function term(array $data, Plan $plan): Term
    {
        if (($data['price_id'] ?? null) === self::CUSTOM) {
            $start = self::date($data['range_starts_on'] ?? null);
            $end = self::date($data['range_ends_on'] ?? null);

            if ($start === null || $end === null) {
                throw new InvalidArgumentException(__('billing::billing.ui.record.dates_required'));
            }

            return new RangeTerm($start, $end);
        }

        /** @var PlanPrice $price */
        $price = $plan->prices()->findOrFail($data['price_id'] ?? null);

        return $price->isCalendar()
            ? $price->calendarTerm(array_map('intval', (array) ($data['calendar_periods'] ?? [])))
            : $price->durationTerm();
    }

    /**
     * @return list<Component>
     */
    private static function whatFields(?Model $billable, bool $group): array
    {
        $plans = Plan::query()->active()->when($billable, fn ($q) => $q->for($billable))->pluck('name', 'id')->all();

        return [
            Select::make('plan_id')
                ->label(__('billing::billing.ui.record.plan'))
                ->options($plans)
                ->default(count($plans) === 1 ? array_key_first($plans) : null)
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('price_id', null);
                    $set('gateway_instance_id', null);
                }),
            Select::make('price_id')
                ->label(__('billing::billing.ui.record.price'))
                ->options(fn (Get $get): array => self::priceOptions($get('plan_id')))
                ->required()
                ->live()
                ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                    $price = self::price($get('plan_id'), $state);
                    $set('amount', $price === null ? null : Money::toMajor($price->amount, $price->plan->currency));
                }),
            CheckboxList::make('calendar_periods')
                ->label(__('billing::billing.ui.record.calendar_periods'))
                ->options(fn (Get $get): array => self::calendarOptions(self::price($get('plan_id'), $get('price_id'))))
                ->visible(fn (Get $get): bool => (bool) self::price($get('plan_id'), $get('price_id'))?->isCalendar())
                ->required(),
            DatePicker::make('starts_on')
                ->label(__('billing::billing.ui.record.starts_on'))
                ->helperText(fn (Get $get): string => self::startHelp($billable, $group, $get('plan_id')))
                ->visible(fn (Get $get): bool => self::price($get('plan_id'), $get('price_id'))?->duration_unit !== null),
            DatePicker::make('range_starts_on')
                ->label(__('billing::billing.ui.record.range_starts_on'))
                ->visible(fn (Get $get): bool => $get('price_id') === self::CUSTOM)
                ->required(),
            DatePicker::make('range_ends_on')
                ->label(__('billing::billing.ui.record.range_ends_on'))
                ->visible(fn (Get $get): bool => $get('price_id') === self::CUSTOM)
                ->afterOrEqual('range_starts_on')
                ->required(),
            TextInput::make('amount')
                ->label($group ? __('billing::billing.ui.record.amount_each') : __('billing::billing.ui.record.amount'))
                ->numeric()
                ->minValue(0)
                ->required()
                ->suffix(fn (Get $get): ?string => Plan::query()->find($get('plan_id'))?->currency),
        ];
    }

    /**
     * @return list<Component>
     */
    private static function howFields(): array
    {
        $catalog = app(GatewayCatalog::class);
        $instance = fn (Get $get): ?GatewayInstance => GatewayInstance::query()->active()->find($get('gateway_instance_id'));
        $needs = fn (string $setting) => fn (Get $get): bool => (bool) $instance($get)?->setting($setting)
            && $get('method') !== OfflineProvider::COMPLIMENTARY;

        return [
            Select::make('gateway_instance_id')
                ->label(__('billing::billing.ui.record.gateway'))
                ->options(fn (Get $get): array => $catalog->instanceOptions(Plan::query()->find($get('plan_id'))?->currency))
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('method', null)),
            Select::make('method')
                ->label(__('billing::billing.ui.record.method'))
                ->options(fn (Get $get): array => $catalog->methodOptions($instance($get)))
                ->required()
                ->live(),
            Text::make(fn (Get $get): string => (string) $instance($get)?->setting('instructions'))
                ->visible(fn (Get $get): bool => filled($instance($get)?->setting('instructions'))),
            DatePicker::make('paid_on')
                ->label(__('billing::billing.ui.record.paid_on'))
                ->default(now())
                ->maxDate(now())
                ->required(),
            TextInput::make('reference')
                ->label(__('billing::billing.ui.record.reference'))
                ->maxLength(191)
                ->required($needs('require_reference')),
            FileUpload::make('proof')
                ->label(__('billing::billing.ui.record.proof'))
                ->disk((string) config('billing.proofs.disk', 'local'))
                ->directory((string) config('billing.proofs.directory', 'billing/proofs'))
                ->visibility('private')
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf'])
                ->maxSize(5120)
                ->required($needs('require_proof')),
            Textarea::make('notes')->label(__('billing::billing.ui.record.notes'))->rows(2)->maxLength(1000),
            Toggle::make('allow_overlap')
                ->label(__('billing::billing.ui.record.allow_overlap'))
                ->helperText(__('billing::billing.ui.record.allow_overlap_help')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function priceOptions(mixed $planId): array
    {
        $plan = Plan::query()->with('prices')->find($planId);

        if ($plan === null) {
            return [];
        }

        $options = $plan->prices
            ->mapWithKeys(fn (PlanPrice $p): array => [(string) $p->id => $p->label.' — '.Money::format($p->amount, $plan->currency)])
            ->all();

        return $options + [self::CUSTOM => __('billing::billing.ui.record.custom_dates')];
    }

    private static function price(mixed $planId, mixed $priceId): ?PlanPrice
    {
        if (! is_numeric($priceId)) {
            return null;
        }

        return PlanPrice::query()->with('plan')->where('plan_id', $planId)->find($priceId);
    }

    /**
     * @return array<int, string>
     */
    private static function calendarOptions(?PlanPrice $price): array
    {
        if ($price?->calendar_id === null) {
            return [];
        }

        return CalendarPeriod::query()
            ->where('calendar_id', $price->calendar_id)
            ->where('ends_on', '>=', CarbonImmutable::today()->subYear())
            ->orderBy('starts_on')
            ->get()
            ->mapWithKeys(fn (CalendarPeriod $p): array => [$p->id => "{$p->label} ({$p->starts_on->isoFormat('ll')} – {$p->ends_on->isoFormat('ll')})"])
            ->all();
    }

    private static function startHelp(?Model $billable, bool $group, mixed $planId): string
    {
        if ($group || ! $billable instanceof Billable) {
            return __('billing::billing.ui.record.starts_on_group_help');
        }

        $until = $billable->paidUntil(Plan::query()->find($planId));

        return $until === null
            ? __('billing::billing.ui.record.starts_on_new_help')
            : __('billing::billing.ui.record.starts_on_help', ['date' => $until->isoFormat('LL')]);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return blank($value) ? null : CarbonImmutable::parse((string) $value)->startOfDay();
    }

    /**
     * The proof path comes back from the browser as a string, so accept only
     * a fresh Filament upload name in the proofs directory that exists and
     * no other payment uses; anything else could point at another file.
     */
    private static function proofPath(mixed $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $directory = preg_quote(trim((string) config('billing.proofs.directory', 'billing/proofs'), '/'), '#');
        $valid = is_string($path)
            && preg_match('#^'.$directory.'/[0-9A-Za-z]{26}\.(jpe?g|png|webp|pdf)$#i', $path) === 1
            && Storage::disk((string) config('billing.proofs.disk', 'local'))->exists($path)
            && ! Payment::query()->where('proof_path', $path)->exists();

        if (! $valid) {
            throw new InvalidArgumentException(__('billing::billing.ui.record.invalid_proof'));
        }

        return $path;
    }
}
