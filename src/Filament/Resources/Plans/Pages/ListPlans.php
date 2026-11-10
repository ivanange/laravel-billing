<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\Plans\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Ivanange\Billing\Filament\Resources\Plans\PlanResource;

class ListPlans extends ListRecords
{
    protected static string $resource = PlanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
