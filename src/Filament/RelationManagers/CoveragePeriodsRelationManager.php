<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Filament\Actions\RecordPaymentAction;
use Ivanange\Billing\Filament\Resources\Payments\PaymentResource;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Support\Money;

/**
 * Add to any billable resource's getRelations() to show its paid periods
 * and record a payment from there.
 */
class CoveragePeriodsRelationManager extends RelationManager
{
    protected static string $relationship = 'coveragePeriods';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('billing::billing.ui.period.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('billing.view');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_on', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['subscription.plan', 'payments']))
            ->columns([
                TextColumn::make('subscription.plan.name')->label(__('billing::billing.ui.plan.label')),
                TextColumn::make('label')->label(__('billing::billing.ui.period.label'))->placeholder('—'),
                TextColumn::make('starts_on')->label(__('billing::billing.ui.calendar.starts_on'))->date(),
                TextColumn::make('ends_on')->label(__('billing::billing.ui.calendar.ends_on'))->date(),
                TextColumn::make('amount')->label(__('billing::billing.ui.payment.amount'))
                    ->formatStateUsing(fn (CoveragePeriod $record): string => Money::format($record->amount, $record->currency)),
                TextColumn::make('status')->label(__('billing::billing.ui.payment.status'))->badge()
                    ->formatStateUsing(fn (PeriodStatus $state): string => $state->label())
                    ->color(fn (PeriodStatus $state): string => match ($state) {
                        PeriodStatus::Paid => 'success',
                        PeriodStatus::Pending => 'warning',
                        PeriodStatus::Void => 'gray',
                    }),
            ])
            ->headerActions([
                RecordPaymentAction::make()->billableUsing(fn (RelationManager $livewire): Model => $livewire->getOwnerRecord()),
            ])
            ->recordActions([
                Action::make('payment')
                    ->label(__('billing::billing.ui.payment.label'))
                    ->icon('heroicon-o-eye')
                    ->url(fn (CoveragePeriod $record): ?string => ($payment = $record->payments->first())
                        ? PaymentResource::getUrl('view', ['record' => $payment])
                        : null),
            ]);
    }
}
