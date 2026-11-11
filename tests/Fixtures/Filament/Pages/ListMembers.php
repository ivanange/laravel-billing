<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Fixtures\Filament\Pages;

use Filament\Resources\Pages\ListRecords;
use Ivanange\Billing\Tests\Fixtures\Filament\MemberResource;

class ListMembers extends ListRecords
{
    protected static string $resource = MemberResource::class;
}
