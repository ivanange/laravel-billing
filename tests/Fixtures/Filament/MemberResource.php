<?php

declare(strict_types=1);

namespace Ivanange\Billing\Tests\Fixtures\Filament;

use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Ivanange\Billing\Filament\Actions\PayLinkAction;
use Ivanange\Billing\Filament\Actions\RecordGroupPaymentBulkAction;
use Ivanange\Billing\Filament\Actions\RecordPaymentAction;
use Ivanange\Billing\Filament\RelationManagers\CoveragePeriodsRelationManager;
use Ivanange\Billing\Tests\Fixtures\Member;

/** How an app wires the billing actions into its own billable resource. */
class MemberResource extends Resource
{
    protected static ?string $model = Member::class;

    public static function table(Table $table): Table
    {
        return $table
            ->columns([TextColumn::make('name')])
            ->recordActions([RecordPaymentAction::make(), PayLinkAction::make()])
            ->toolbarActions([RecordGroupPaymentBulkAction::make()]);
    }

    public static function getRelations(): array
    {
        return [CoveragePeriodsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMembers::route('/'),
            'edit' => Pages\EditMember::route('/{record}/edit'),
        ];
    }
}
