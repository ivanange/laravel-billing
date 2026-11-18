<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\Payments\Pages;

use Filament\Resources\Pages\ViewRecord;
use Ivanange\Billing\Filament\Actions\ConfirmPaymentAction;
use Ivanange\Billing\Filament\Actions\RefundPaymentAction;
use Ivanange\Billing\Filament\Actions\ResolveStuckRefundAction;
use Ivanange\Billing\Filament\Actions\VoidPaymentAction;
use Ivanange\Billing\Filament\Resources\Payments\PaymentResource;

class ViewPayment extends ViewRecord
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [ConfirmPaymentAction::make(), RefundPaymentAction::make(), ResolveStuckRefundAction::make(), VoidPaymentAction::make()];
    }
}
