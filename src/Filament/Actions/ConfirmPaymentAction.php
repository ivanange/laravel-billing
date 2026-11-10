<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\PaymentConfirmer;

class ConfirmPaymentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'confirmPayment';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('billing::billing.ui.payment.confirm'))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('billing::billing.ui.payment.confirm_help'))
            ->visible(fn (Payment $record): bool => $record->status === PaymentStatus::Recorded && Gate::allows('billing.confirm', $record))
            ->action(function (Payment $record, Action $action): void {
                Gate::authorize('billing.confirm', $record);

                try {
                    app(PaymentConfirmer::class)->confirm($record, auth()->user());
                } catch (BillingException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title(__('billing::billing.ui.payment.confirmed'))->send();
            });
    }
}
