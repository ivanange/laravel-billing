<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\GatewayInstances\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Ivanange\Billing\Filament\Resources\GatewayInstances\GatewayInstanceResource;

class ListGatewayInstances extends ListRecords
{
    protected static string $resource = GatewayInstanceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
