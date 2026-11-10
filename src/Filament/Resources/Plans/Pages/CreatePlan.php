<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\Plans\Pages;

use Filament\Resources\Pages\CreateRecord;
use Ivanange\Billing\Filament\Resources\Plans\PlanResource;

class CreatePlan extends CreateRecord
{
    protected static string $resource = PlanResource::class;
}
