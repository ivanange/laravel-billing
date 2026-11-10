<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\Subscriptions;

use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Filament\Concerns\GatedResource;
use Ivanange\Billing\Models\Subscription;

/**
 * Read-only overview: who is covered, on which plan, until when.
 */
class SubscriptionResource extends Resource
{
    use GatedResource;

    protected static ?string $model = Subscription::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'billing/subscriptions';

    protected static function ability(): string
    {
        return 'billing.view';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getModelLabel(): string
    {
        return __('billing::billing.ui.subscription.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('billing::billing.ui.subscription.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['billable', 'plan'])
            ->withExists(['coveragePeriods as covered_now' => fn (Builder $q) => self::coveringToday($q)]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('paid_until')
            ->columns([
                TextColumn::make('billable')
                    ->label(__('billing::billing.ui.payment.for'))
                    ->formatStateUsing(fn (Subscription $record): string => $record->billable?->billingName() ?? '—'),
                TextColumn::make('plan.name')->label(__('billing::billing.ui.plan.label')),
                TextColumn::make('paid_until')->label(__('billing::billing.ui.subscription.paid_until'))->date()->sortable()->placeholder('—'),
                IconColumn::make('covered_now')->label(__('billing::billing.ui.subscription.covered_now'))->boolean(),
            ])
            ->filters([
                TernaryFilter::make('covered')
                    ->label(__('billing::billing.ui.subscription.covered_now'))
                    ->queries(
                        true: fn (Builder $q) => $q->whereHas('coveragePeriods', fn (Builder $p) => self::coveringToday($p)),
                        false: fn (Builder $q) => $q->whereDoesntHave('coveragePeriods', fn (Builder $p) => self::coveringToday($p)),
                    ),
                SelectFilter::make('plan_id')->label(__('billing::billing.ui.plan.label'))->relationship('plan', 'name'),
            ]);
    }

    private static function coveringToday(Builder $periods): Builder
    {
        $today = CarbonImmutable::today();

        return $periods->where('status', PeriodStatus::Paid->value)
            ->where('starts_on', '<=', $today)
            ->where('grace_ends_on', '>=', $today);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSubscriptions::route('/')];
    }
}
