<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\Payments;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Ivanange\Billing\Enums\FailureReason;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Enums\PeriodStatus;
use Ivanange\Billing\Filament\Actions\ConfirmPaymentAction;
use Ivanange\Billing\Filament\Actions\RefundPaymentAction;
use Ivanange\Billing\Filament\Actions\ResolveStuckRefundAction;
use Ivanange\Billing\Filament\Actions\VoidPaymentAction;
use Ivanange\Billing\Filament\Concerns\GatedResource;
use Ivanange\Billing\Models\CoveragePeriod;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Support\BillableName;
use Ivanange\Billing\Support\Money;

/**
 * Payments are recorded from billable resources (RecordPaymentAction); this
 * resource lists them for review, confirmation and voiding.
 */
class PaymentResource extends Resource
{
    use GatedResource;

    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'billing/payments';

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
        return __('billing::billing.ui.payment.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('billing::billing.ui.payment.plural');
    }

    /** Payments waiting on a person: awaiting confirmation, or a refund in progress (possibly stuck). */
    public static function getNavigationBadge(): ?string
    {
        $total = array_sum(self::needingAttention());

        return $total > 0 ? (string) $total : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return self::needingAttention()['refunding'] > 0 ? 'danger' : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('billing::billing.ui.payment.attention_badge', self::needingAttention());
    }

    /**
     * @return array{awaiting: int, refunding: int}
     */
    private static function needingAttention(): array
    {
        $counts = Payment::query()
            ->whereIn('status', [PaymentStatus::Recorded->value, PaymentStatus::Refunding->value])
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'awaiting' => (int) ($counts[PaymentStatus::Recorded->value] ?? 0),
            'refunding' => (int) ($counts[PaymentStatus::Refunding->value] ?? 0),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['gatewayInstance', 'coveragePeriods.billable']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('paid_on')->label(__('billing::billing.ui.payment.paid_on'))->date()->sortable(),
                TextColumn::make('beneficiaries')
                    ->label(__('billing::billing.ui.payment.for'))
                    ->state(fn (Payment $record): string => implode(', ', $record->beneficiaryNames()))
                    ->limit(40),
                TextColumn::make('amount')
                    ->label(__('billing::billing.ui.payment.amount'))
                    ->formatStateUsing(fn (Payment $record): string => $record->formattedAmount())
                    ->sortable(),
                TextColumn::make('method')
                    ->label(__('billing::billing.ui.payment.method'))
                    ->formatStateUsing(fn (Payment $record): string => $record->methodLabel()),
                TextColumn::make('gatewayInstance.name')->label(__('billing::billing.ui.gateway.label'))->toggleable(),
                self::statusColumn(),
                TextColumn::make('failure_reason')
                    ->label(__('billing::billing.ui.payment.failure_reason'))
                    ->formatStateUsing(fn (string $state): string => self::failureLabel($state))
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('reference')->label(__('billing::billing.ui.record.reference'))->searchable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('billing::billing.ui.payment.status'))->options(PaymentStatus::options()),
                // "unverifiable" marks failed payments that may really have been paid.
                SelectFilter::make('failure_reason')->label(__('billing::billing.ui.payment.failure_reason'))
                    ->options(FailureReason::options()),
                SelectFilter::make('gateway_instance_id')->label(__('billing::billing.ui.gateway.label'))->relationship('gatewayInstance', 'name'),
            ])
            ->recordActions([ViewAction::make(), ConfirmPaymentAction::make(), RefundPaymentAction::make(), ResolveStuckRefundAction::make(), VoidPaymentAction::make()]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(3)->schema([
                TextEntry::make('amount')->label(__('billing::billing.ui.payment.amount'))->formatStateUsing(fn (Payment $record): string => $record->formattedAmount()),
                TextEntry::make('status')->label(__('billing::billing.ui.payment.status'))->badge()
                    ->formatStateUsing(fn (PaymentStatus $state): string => $state->label())
                    ->color(fn (PaymentStatus $state): string => self::statusColor($state)),
                TextEntry::make('paid_on')->label(__('billing::billing.ui.payment.paid_on'))->date(),
                TextEntry::make('method')->label(__('billing::billing.ui.payment.method'))->formatStateUsing(fn (Payment $record): string => $record->methodLabel()),
                TextEntry::make('gatewayInstance.name')->label(__('billing::billing.ui.gateway.label')),
                TextEntry::make('reference')->label(__('billing::billing.ui.record.reference'))->placeholder('—'),
                TextEntry::make('recorder.name')->label(__('billing::billing.ui.payment.recorded_by'))->placeholder('—'),
                TextEntry::make('confirmer.name')->label(__('billing::billing.ui.payment.confirmed_by'))->placeholder('—'),
                TextEntry::make('confirmed_at')->label(__('billing::billing.ui.payment.confirmed_at'))->dateTime()->placeholder('—'),
                TextEntry::make('failure_reason')->label(__('billing::billing.ui.payment.failure_reason'))
                    ->visible(fn (Payment $record): bool => $record->status === PaymentStatus::Failed)
                    ->formatStateUsing(fn (string $state): string => self::failureLabel($state)),
                TextEntry::make('refundResolver.name')->label(__('billing::billing.ui.payment.refund_resolved_by'))
                    ->visible(fn (Payment $record): bool => $record->refund_resolved_at !== null)->placeholder('—'),
                TextEntry::make('refund_resolved_at')->label(__('billing::billing.ui.payment.refund_resolved_at'))
                    ->visible(fn (Payment $record): bool => $record->refund_resolved_at !== null)->dateTime(),
                TextEntry::make('external_id')->label(__('billing::billing.ui.payment.reference'))
                    ->visible(fn (Payment $record): bool => $record->isOnline())->placeholder('—'),
                TextEntry::make('notes')->label(__('billing::billing.ui.record.notes'))->placeholder('—')->columnSpanFull(),
                TextEntry::make('proof_path')
                    ->label(__('billing::billing.ui.record.proof'))
                    ->formatStateUsing(fn (): string => __('billing::billing.ui.payment.download_proof'))
                    ->url(fn (Payment $record): ?string => $record->proof_path ? route('billing.payments.proof', $record) : null, shouldOpenInNewTab: true)
                    ->placeholder('—'),
            ]),
            Section::make(__('billing::billing.ui.payment.voided_section'))
                ->visible(fn (Payment $record): bool => $record->status === PaymentStatus::Void)
                ->columns(3)
                ->schema([
                    TextEntry::make('voider.name')->label(__('billing::billing.ui.payment.voided_by'))->placeholder('—'),
                    TextEntry::make('voided_at')->label(__('billing::billing.ui.payment.voided_at'))->dateTime(),
                    TextEntry::make('void_reason')->label(__('billing::billing.ui.payment.void_reason')),
                ]),
            Section::make(__('billing::billing.ui.payment.covers'))->schema([
                RepeatableEntry::make('coveragePeriods')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('billable')->label(__('billing::billing.ui.payment.for'))
                        ->formatStateUsing(fn (CoveragePeriod $record): string => BillableName::of($record->billable)),
                    TextEntry::make('starts_on')->label(__('billing::billing.ui.period.dates'))
                        ->formatStateUsing(fn (CoveragePeriod $record): string => $record->starts_on->isoFormat('ll').' – '.$record->ends_on->isoFormat('ll')),
                    TextEntry::make('amount')->label(__('billing::billing.ui.payment.amount'))
                        ->formatStateUsing(fn (CoveragePeriod $record): string => Money::format($record->amount, $record->currency)),
                    TextEntry::make('status')->label(__('billing::billing.ui.payment.status'))->badge()
                        ->formatStateUsing(fn (PeriodStatus $state): string => $state->label()),
                ]),
            ]),
        ]);
    }

    public static function statusColumn(): TextColumn
    {
        return TextColumn::make('status')
            ->label(__('billing::billing.ui.payment.status'))
            ->badge()
            ->formatStateUsing(fn (PaymentStatus $state): string => $state->label())
            ->color(fn (PaymentStatus $state): string => self::statusColor($state));
    }

    /** A value this version does not know (written by a host or a later version) is shown as stored. */
    private static function failureLabel(string $reason): string
    {
        return FailureReason::tryFrom($reason)?->label() ?? $reason;
    }

    private static function statusColor(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Recorded, PaymentStatus::Pending => 'warning',
            PaymentStatus::Confirmed => 'success',
            PaymentStatus::Failed => 'danger',
            PaymentStatus::Refunding, PaymentStatus::Refunded => 'info',
            PaymentStatus::Void => 'gray',
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'view' => Pages\ViewPayment::route('/{record}'),
        ];
    }
}
