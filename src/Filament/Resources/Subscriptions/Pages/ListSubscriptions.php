<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\Subscriptions\Pages;

use Filament\Resources\Pages\ListRecords;
use Ivanange\Billing\Filament\Resources\Subscriptions\SubscriptionResource;

class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionResource::class;
}
