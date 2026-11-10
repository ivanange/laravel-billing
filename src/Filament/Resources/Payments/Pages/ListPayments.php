<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\Payments\Pages;

use Filament\Resources\Pages\ListRecords;
use Ivanange\Billing\Filament\Resources\Payments\PaymentResource;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;
}
