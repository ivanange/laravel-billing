<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Actions;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Ivanange\Billing\Contracts\Refunds;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\PaymentRefunder;

/**
 * Full refund through the payment's own gateway instance. Shown only for
 * confirmed payments whose provider supports refunds.
 */
class RefundPaymentAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'refundPayment';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('billing::billing.ui.payment.refund'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('billing::billing.ui.payment.refund_help'))
            ->visible(fn (Payment $record): bool => $record->status === PaymentStatus::Confirmed
                && $record->gatewayInstance->gateway() instanceof Refunds
                && Gate::allows('billing.void', $record))
            ->action(function (Payment $record, Action $action): void {
                Gate::authorize('billing.void', $record);

                try {
                    app(PaymentRefunder::class)->refund($record, auth()->user());
                } catch (BillingException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title(__('billing::billing.ui.payment.refunded'))->send();
            });
    }
}
