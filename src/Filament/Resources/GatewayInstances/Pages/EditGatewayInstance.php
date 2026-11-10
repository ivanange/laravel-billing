<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\GatewayInstances\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Ivanange\Billing\Filament\Resources\GatewayInstances\GatewayInstanceResource;
use Ivanange\Billing\Models\GatewayInstance;

class EditGatewayInstance extends EditRecord
{
    protected static string $resource = GatewayInstanceResource::class;

    /**
     * Secrets are never sent back to the browser.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['credentials']);

        return $data;
    }

    /**
     * Blank secret fields keep the stored value; filled ones replace it.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var GatewayInstance $record */
        $record = $this->getRecord();
        $typed = array_filter((array) ($data['credentials'] ?? []), fn (mixed $value): bool => filled($value));
        $data['credentials'] = array_merge((array) $record->credentials, $typed);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
