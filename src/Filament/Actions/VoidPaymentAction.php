<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\PaymentVoider;

class VoidPaymentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'voidPayment';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('billing::billing.ui.payment.void'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->modalDescription(__('billing::billing.ui.payment.void_help'))
            ->schema([
                Textarea::make('reason')->label(__('billing::billing.ui.payment.void_reason'))->required()->maxLength(1000),
            ])
            ->visible(fn (Payment $record): bool => in_array($record->status, PaymentVoider::voidable(), true) && Gate::allows('billing.void', $record))
            ->action(function (Payment $record, array $data, Action $action): void {
                Gate::authorize('billing.void', $record);

                try {
                    app(PaymentVoider::class)->void($record, (string) $data['reason'], auth()->user());
                } catch (BillingException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title(__('billing::billing.ui.payment.voided'))->send();
            });
    }
}
