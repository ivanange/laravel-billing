<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\Plans\Pages;

use Filament\Resources\Pages\EditRecord;
use Ivanange\Billing\Filament\Resources\Plans\PlanResource;

class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;
}
