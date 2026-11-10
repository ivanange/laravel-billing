<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\Calendars\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Ivanange\Billing\Filament\Resources\Calendars\BillingCalendarResource;

class ListBillingCalendars extends ListRecords
{
    protected static string $resource = BillingCalendarResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
