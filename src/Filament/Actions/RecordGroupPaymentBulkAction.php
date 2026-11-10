<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Actions;

use Filament\Actions\BulkAction;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Ivanange\Billing\Filament\Forms\RecordPaymentForm;

/**
 * One payment covering every selected billable, e.g. a co-operative paying
 * for all its members at once.
 */
class RecordGroupPaymentBulkAction extends BulkAction
{
    public static function getDefaultName(): ?string
    {
        return 'recordGroupPayment';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('billing::billing.ui.record.group_action'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->modalHeading(fn (?Collection $records): string => __('billing::billing.ui.record.group_heading', ['count' => $records?->count() ?? 0]))
            ->modalSubmitActionLabel(__('billing::billing.ui.record.submit'))
            ->schema(fn (): array => RecordPaymentForm::components(null, group: true))
            ->visible(fn (): bool => Gate::allows('billing.record'))
            ->action(fn (array $data, Collection $records, BulkAction $action) => RecordPaymentForm::handle($data, $records->values()->all(), $action))
            ->deselectRecordsAfterCompletion();
    }
}
