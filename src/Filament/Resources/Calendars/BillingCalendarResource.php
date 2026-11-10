<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\Calendars;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Ivanange\Billing\Filament\Concerns\GatedResource;
use Ivanange\Billing\Models\BillingCalendar;

/**
 * Fixed named periods (school terms, farming seasons) that prices can sell.
 */
class BillingCalendarResource extends Resource
{
    use GatedResource;

    protected static ?string $model = BillingCalendar::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'billing/calendars';

    protected static function ability(): string
    {
        return 'billing.manage';
    }

    public static function getModelLabel(): string
    {
        return __('billing::billing.ui.calendar.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('billing::billing.ui.calendar.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('billing::billing.ui.calendar.name'))->placeholder(__('billing::billing.ui.calendar.name_placeholder'))->required()->maxLength(255),
            Repeater::make('periods')
                ->label(__('billing::billing.ui.calendar.periods'))
                ->relationship()
                ->columns(3)
                ->columnSpanFull()
                ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                ->schema([
                    TextInput::make('label')->label(__('billing::billing.ui.calendar.period_label'))->placeholder(__('billing::billing.ui.calendar.period_placeholder'))->required()->maxLength(100),
                    DatePicker::make('starts_on')->label(__('billing::billing.ui.calendar.starts_on'))->required(),
                    DatePicker::make('ends_on')->label(__('billing::billing.ui.calendar.ends_on'))->required()->afterOrEqual('starts_on'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('billing::billing.ui.calendar.name'))->searchable(),
                TextColumn::make('periods.label')->label(__('billing::billing.ui.calendar.periods'))->badge(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBillingCalendars::route('/'),
            'create' => Pages\CreateBillingCalendar::route('/create'),
            'edit' => Pages\EditBillingCalendar::route('/{record}/edit'),
        ];
    }
}
