<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\Calendars\Pages;

use Filament\Resources\Pages\CreateRecord;
use Ivanange\Billing\Filament\Resources\Calendars\BillingCalendarResource;

class CreateBillingCalendar extends CreateRecord
{
    protected static string $resource = BillingCalendarResource::class;
}
