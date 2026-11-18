<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;
use Ivanange\Billing\Enums\PaymentStatus;
use Ivanange\Billing\Exceptions\BillingException;
use Ivanange\Billing\Models\Payment;
use Ivanange\Billing\Services\PaymentRefunder;

/**
 * Settles a payment left "refunding" because the provider never answered
 * the refund clearly. The person says what the provider's dashboard shows;
 * the provider is not called again.
 */
class ResolveStuckRefundAction extends Action
{
    private const REFUNDED = 'refunded';

    private const NOT_REFUNDED = 'not_refunded';

    public static function getDefaultName(): ?string
    {
        return 'resolveStuckRefund';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('billing::billing.ui.payment.resolve_refund'))
            ->icon(Heroicon::OutlinedQuestionMarkCircle)
            ->color('warning')
            ->modalDescription(__('billing::billing.ui.payment.resolve_refund_help'))
            ->modalSubmitActionLabel(__('billing::billing.ui.payment.resolve_refund_submit'))
            ->schema([
                Radio::make('outcome')
                    ->label(__('billing::billing.ui.payment.resolve_refund_outcome'))
                    ->options([
                        self::REFUNDED => __('billing::billing.ui.payment.refund_went_through'),
                        self::NOT_REFUNDED => __('billing::billing.ui.payment.refund_did_not_go_through'),
                    ])
                    ->required(),
            ])
            ->visible(fn (Payment $record): bool => $record->status === PaymentStatus::Refunding
                && Gate::allows('billing.refund', $record))
            ->action(function (Payment $record, array $data, Action $action): void {
                Gate::authorize('billing.refund', $record);
                $refunded = $data['outcome'] === self::REFUNDED;

                try {
                    app(PaymentRefunder::class)->resolveStuck($record, $refunded, auth()->user());
                } catch (BillingException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()
                    ->title(__($refunded ? 'billing::billing.ui.payment.stuck_refunded' : 'billing::billing.ui.payment.stuck_not_refunded'))
                    ->send();
            });
    }
}
