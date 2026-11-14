<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Fixtures\Filament\Pages;

use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Schema;
use Ivanange\Billing\Tests\Fixtures\Filament\MemberResource;

class EditMember extends EditRecord
{
    protected static string $resource = MemberResource::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')]);
    }
}
