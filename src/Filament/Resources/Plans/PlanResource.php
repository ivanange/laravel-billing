<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\Plans;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Ivanange\Billing\Enums\DurationUnit;
use Ivanange\Billing\Filament\BillingPlugin;
use Ivanange\Billing\Filament\Concerns\GatedResource;
use Ivanange\Billing\Models\BillingCalendar;
use Ivanange\Billing\Models\Plan;
use Ivanange\Billing\Support\Money;

class PlanResource extends Resource
{
    use GatedResource;

    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'billing/plans';

    protected static function ability(): string
    {
        return 'billing.manage';
    }

    public static function getModelLabel(): string
    {
        return __('billing::billing.ui.plan.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('billing::billing.ui.plan.plural');
    }

    public static function form(Schema $schema): Schema
    {
        $billables = BillingPlugin::get()->getBillableOptions();

        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('billing::billing.ui.plan.name'))->required()->maxLength(255),
            TextInput::make('currency')
                ->label(__('billing::billing.ui.plan.currency'))
                ->helperText(__('billing::billing.ui.plan.currency_help'))
                ->required()
                ->length(3)
                ->alpha()
                ->live(onBlur: true)
                ->dehydrateStateUsing(fn (?string $state): string => strtoupper((string) $state)),
            Textarea::make('description')->label(__('billing::billing.ui.plan.description'))->rows(2)->columnSpanFull(),
            TextInput::make('grace_days')
                ->label(__('billing::billing.ui.plan.grace_days'))
                ->helperText(__('billing::billing.ui.plan.grace_help'))
                ->numeric()->integer()->minValue(0)->maxValue(365)->default(0)->required(),
            Select::make('billable_type')
                ->label(__('billing::billing.ui.plan.billable_type'))
                ->placeholder(__('billing::billing.ui.plan.any_billable'))
                ->options($billables)
                ->in(array_keys($billables))
                ->visible($billables !== []),
            Toggle::make('is_active')->label(__('billing::billing.ui.plan.active'))->default(true),
            Repeater::make('prices')
                ->label(__('billing::billing.ui.plan.prices'))
                ->helperText(__('billing::billing.ui.plan.prices_help'))
                ->relationship()
                ->orderColumn('sort')
                ->columns(4)
                ->columnSpanFull()
                ->itemLabel(fn (array $state): ?string => $state['label'] ?? null)
                ->schema(self::priceFields()),
        ]);
    }

    /**
     * @return array<int, Component>
     */
    private static function priceFields(): array
    {
        $currency = fn (Get $get): string => strtoupper((string) ($get('../../currency') ?: 'USD'));

        return [
            TextInput::make('label')->label(__('billing::billing.ui.price.label'))->placeholder(__('billing::billing.ui.price.label_placeholder'))->required()->maxLength(100),
            ToggleButtons::make('kind')
                ->label(__('billing::billing.ui.price.kind'))
                ->options(['duration' => __('billing::billing.ui.price.duration'), 'calendar' => __('billing::billing.ui.price.calendar')])
                ->default('duration')
                ->inline()
                ->live()
                ->dehydrated(false)
                ->afterStateHydrated(fn (ToggleButtons $component, Get $get) => $component->state($get('calendar_id') ? 'calendar' : 'duration'))
                ->visible(fn (): bool => BillingCalendar::query()->exists()),
            TextInput::make('duration_count')
                ->label(__('billing::billing.ui.price.count'))
                ->numeric()->integer()->minValue(1)->maxValue(120)->default(1)
                ->required(fn (Get $get): bool => $get('kind') !== 'calendar')
                ->visible(fn (Get $get): bool => $get('kind') !== 'calendar'),
            Select::make('duration_unit')
                ->label(__('billing::billing.ui.price.unit'))
                ->options(DurationUnit::options())
                ->default(DurationUnit::Month->value)
                ->required(fn (Get $get): bool => $get('kind') !== 'calendar')
                ->visible(fn (Get $get): bool => $get('kind') !== 'calendar'),
            Select::make('calendar_id')
                ->label(__('billing::billing.ui.price.calendar'))
                ->options(fn (): array => BillingCalendar::query()->pluck('name', 'id')->all())
                ->required(fn (Get $get): bool => $get('kind') === 'calendar')
                ->visible(fn (Get $get): bool => $get('kind') === 'calendar'),
            TextInput::make('amount')
                ->label(__('billing::billing.ui.price.amount'))
                ->helperText(__('billing::billing.ui.price.amount_help'))
                ->numeric()->minValue(0)->required()
                ->suffix(fn (Get $get): string => $currency($get))
                ->formatStateUsing(fn (mixed $state, Get $get): ?float => is_numeric($state) ? Money::toMajor((int) $state, $currency($get)) : null)
                ->dehydrateStateUsing(fn (mixed $state, Get $get): ?int => is_numeric($state) ? Money::toMinor((string) $state, $currency($get)) : null),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('billing::billing.ui.plan.name'))->searchable()->sortable(),
                TextColumn::make('currency')->label(__('billing::billing.ui.plan.currency')),
                TextColumn::make('prices.label')->label(__('billing::billing.ui.plan.prices'))->badge(),
                TextColumn::make('grace_days')->label(__('billing::billing.ui.plan.grace_days')),
                IconColumn::make('is_active')->label(__('billing::billing.ui.plan.active'))->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPlans::route('/'),
            'create' => Pages\CreatePlan::route('/create'),
            'edit' => Pages\EditPlan::route('/{record}/edit'),
        ];
    }
}
