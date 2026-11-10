<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Ivanange\Billing\Filament\Forms\RecordPaymentForm;

/**
 * Record a payment for one billable. Works as a table row action or a page
 * header action; inside a relation manager pass the owner with
 * ->billableUsing(fn ($livewire) => $livewire->getOwnerRecord()).
 */
class RecordPaymentAction extends Action
{
    private ?Closure $billableResolver = null;

    public static function getDefaultName(): ?string
    {
        return 'recordPayment';
    }

    public function billableUsing(Closure $resolver): static
    {
        $this->billableResolver = $resolver;

        return $this;
    }

    public function resolveBillable(): ?Model
    {
        $billable = $this->billableResolver === null ? $this->getRecord() : $this->evaluate($this->billableResolver);

        return $billable instanceof Model ? $billable : null;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('billing::billing.ui.record.action'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->modalHeading(fn (): string => __('billing::billing.ui.record.heading', ['name' => $this->resolveBillable()?->billingName()]))
            ->modalSubmitActionLabel(__('billing::billing.ui.record.submit'))
            ->schema(fn (): array => RecordPaymentForm::components($this->resolveBillable(), group: false))
            ->visible(fn (): bool => Gate::allows('billing.record'))
            ->action(function (array $data, Action $action): void {
                $billable = $this->resolveBillable();
                abort_if($billable === null, 404);

                RecordPaymentForm::handle($data, [$billable], $action);
            });
    }
}
