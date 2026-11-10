<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\GatewayInstances\Pages;

use Filament\Resources\Pages\CreateRecord;
use Ivanange\Billing\Filament\Resources\GatewayInstances\GatewayInstanceResource;
use Ivanange\Billing\Gateways\GatewayActivation;
use Ivanange\Billing\Models\GatewayInstance;

class CreateGatewayInstance extends CreateRecord
{
    protected static string $resource = GatewayInstanceResource::class;

    protected function afterCreate(): void
    {
        if ($this->data['activate_now'] ?? false) {
            /** @var GatewayInstance $record */
            $record = $this->getRecord();
            app(GatewayActivation::class)->activate($record);
        }
    }
}
