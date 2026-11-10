<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\Calendars\Pages;

use Filament\Resources\Pages\EditRecord;
use Ivanange\Billing\Filament\Resources\Calendars\BillingCalendarResource;

class EditBillingCalendar extends EditRecord
{
    protected static string $resource = BillingCalendarResource::class;
}
