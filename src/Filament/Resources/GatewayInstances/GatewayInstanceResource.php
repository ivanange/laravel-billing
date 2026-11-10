<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Resources\GatewayInstances;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Ivanange\Billing\Filament\Concerns\GatedResource;
use Ivanange\Billing\Filament\Forms\ProviderFields;
use Ivanange\Billing\Gateways\GatewayActivation;
use Ivanange\Billing\Gateways\PaymentMethod;
use Ivanange\Billing\Gateways\ProviderRegistry;
use Ivanange\Billing\Models\GatewayInstance;

class GatewayInstanceResource extends Resource
{
    use GatedResource;

    protected static ?string $model = GatewayInstance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'billing/gateways';

    protected static function ability(): string
    {
        return 'billing.manage';
    }

    public static function getModelLabel(): string
    {
        return __('billing::billing.ui.gateway.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('billing::billing.ui.gateway.plural');
    }

    public static function form(Schema $schema): Schema
    {
        $registry = app(ProviderRegistry::class);
        $isEdit = $schema->getOperation() === 'edit';

        return $schema->columns(2)->components([
            TextInput::make('name')
                ->label(__('billing::billing.ui.gateway.name'))
                ->helperText(__('billing::billing.ui.gateway.name_help'))
                ->required()
                ->maxLength(255),
            Select::make('provider')
                ->label(__('billing::billing.ui.gateway.provider'))
                ->options($registry->options())
                ->required()
                ->live()
                ->disabled($isEdit)
                ->in(array_keys($registry->options())),
            CheckboxList::make('enabled_methods')
                ->label(__('billing::billing.ui.gateway.methods'))
                ->helperText(__('billing::billing.ui.gateway.methods_help'))
                ->options(fn (Get $get): array => self::methodOptions($get('provider')))
                ->required()
                ->columns(2)
                ->columnSpanFull(),
            TagsInput::make('currencies')
                ->label(__('billing::billing.ui.gateway.currencies'))
                ->helperText(__('billing::billing.ui.gateway.currencies_help'))
                ->placeholder('GHS')
                ->nestedRecursiveRules(['string', 'size:3', 'alpha']),
            TextInput::make('sort')
                ->label(__('billing::billing.ui.gateway.sort'))
                ->numeric()
                ->minValue(0)
                ->default(0),
            Section::make(__('billing::billing.ui.gateway.settings'))
                ->schema(fn (Get $get): array => self::providerFields($get('provider'), 'settings'))
                ->visible(fn (Get $get): bool => self::providerFields($get('provider'), 'settings') !== [])
                ->columnSpanFull(),
            Section::make(__('billing::billing.ui.gateway.credentials'))
                ->description(__('billing::billing.ui.gateway.credentials_help'))
                ->schema(fn (Get $get): array => self::providerFields($get('provider'), 'credentials', $isEdit))
                ->visible(fn (Get $get): bool => self::providerFields($get('provider'), 'credentials') !== [])
                ->columnSpanFull(),
            Toggle::make('activate_now')
                ->label(__('billing::billing.ui.gateway.activate_now'))
                ->helperText(__('billing::billing.ui.gateway.activate_help'))
                ->default(true)
                ->dehydrated(false)
                ->visible(! $isEdit),
        ]);
    }

    public static function table(Table $table): Table
    {
        $registry = app(ProviderRegistry::class);

        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label(__('billing::billing.ui.gateway.name'))->searchable()->sortable(),
                TextColumn::make('provider')
                    ->label(__('billing::billing.ui.gateway.provider'))
                    ->formatStateUsing(fn (string $state): string => $registry->options()[$state] ?? $state),
                TextColumn::make('enabled_methods')
                    ->label(__('billing::billing.ui.gateway.methods'))
                    ->state(fn (GatewayInstance $record): array => array_map(
                        fn (PaymentMethod $m): string => $m->label(),
                        $registry->has($record->provider) ? $record->offeredMethods() : [],
                    ))
                    ->badge(),
                IconColumn::make('is_active')->label(__('billing::billing.ui.gateway.active'))->boolean(),
                TextColumn::make('payments_count')->counts('payments')->label(__('billing::billing.ui.payment.plural')),
            ])
            ->filters([TrashedFilter::make()])
            ->recordActions([
                Action::make('activate')
                    ->label(__('billing::billing.ui.gateway.activate'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (GatewayInstance $record): bool => ! $record->is_active && ! $record->trashed())
                    ->requiresConfirmation()
                    ->modalDescription(__('billing::billing.ui.gateway.activate_confirm'))
                    ->action(function (GatewayInstance $record): void {
                        app(GatewayActivation::class)->activate($record);
                        Notification::make()->success()->title(__('billing::billing.ui.gateway.activated', ['name' => $record->name]))->send();
                    }),
                Action::make('deactivate')
                    ->label(__('billing::billing.ui.gateway.deactivate'))
                    ->icon(Heroicon::OutlinedPauseCircle)
                    ->color('gray')
                    ->visible(fn (GatewayInstance $record): bool => $record->is_active)
                    ->requiresConfirmation()
                    ->action(fn (GatewayInstance $record) => app(GatewayActivation::class)->deactivate($record)),
                EditAction::make(),
                DeleteAction::make()->before(fn (GatewayInstance $record) => app(GatewayActivation::class)->deactivate($record)),
                RestoreAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGatewayInstances::route('/'),
            'create' => Pages\CreateGatewayInstance::route('/create'),
            'edit' => Pages\EditGatewayInstance::route('/{record}/edit'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function methodOptions(?string $provider): array
    {
        $registry = app(ProviderRegistry::class);

        if ($provider === null || ! $registry->has($provider)) {
            return [];
        }

        return collect($registry->get($provider)::methods())
            ->mapWithKeys(fn (PaymentMethod $m): array => [$m->key => $m->label()])
            ->all();
    }

    /**
     * @return list<Field>
     */
    private static function providerFields(?string $provider, string $group, bool $isEdit = false): array
    {
        $registry = app(ProviderRegistry::class);

        if ($provider === null || ! $registry->has($provider)) {
            return [];
        }

        $class = $registry->get($provider);
        $fields = $group === 'settings' ? $class::settingFields() : $class::credentialFields();

        return ProviderFields::make($fields, $group, secretsKeepExisting: $isEdit);
    }
}
