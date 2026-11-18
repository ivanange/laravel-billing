<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Forms;

use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use InvalidArgumentException;
use Ivanange\Billing\Contracts\Billable;
use Ivanange\Billing\Filament\BillingPlugin;

/**
 * "Paid by": an optional payer chosen among the billable types registered
 * on BillingPlugin::billables(). Left empty, each billable pays for itself.
 * Which records may be chosen is PayerQuery's business.
 */
final class PayerFields
{
    public const SEARCH_LIMIT = 50;

    /**
     * @return list<Component>
     */
    public static function components(bool $group): array
    {
        $types = self::types();

        return [
            Select::make('payer_type')
                ->label(__('billing::billing.ui.record.payer_type'))
                ->placeholder($group ? __('billing::billing.ui.record.payer_each') : __('billing::billing.ui.record.payer_self'))
                ->options($types)
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('payer_id', null))
                ->visible($types !== []),
            Select::make('payer_id')
                ->label(__('billing::billing.ui.record.payer'))
                ->searchable()
                ->getSearchResultsUsing(fn (Get $get, ?string $search): array => self::search($get('payer_type'), (string) $search))
                ->getOptionLabelUsing(fn (Get $get, mixed $value): ?string => self::find($get('payer_type'), $value)?->billingName())
                ->visible(fn (Get $get): bool => filled($get('payer_type')))
                ->required(fn (Get $get): bool => filled($get('payer_type'))),
        ];
    }

    /**
     * The payer named in the form data, or null for "the billable itself".
     *
     * @param  array<string, mixed>  $data
     */
    public static function resolve(array $data): ?Model
    {
        if (blank($data['payer_type'] ?? null)) {
            return null;
        }

        return self::find($data['payer_type'], $data['payer_id'] ?? null)
            ?? throw new InvalidArgumentException(__('billing::billing.ui.record.invalid_payer'));
    }

    /**
     * @return array<int|string, string> key => billing name
     */
    public static function search(mixed $type, string $search): array
    {
        $class = self::modelClass($type);
        $search = trim($search);

        return $class === null || $search === '' ? [] : PayerQuery::search($class, $search, self::SEARCH_LIMIT);
    }

    private static function find(mixed $type, mixed $id): (Model&Billable)|null
    {
        $class = self::modelClass($type);

        return $class === null || blank($id) ? null : PayerQuery::find($class, $id);
    }

    /**
     * Only types registered on the plugin count: the submitted type is a
     * client-controlled string.
     *
     * @return class-string<Model&Billable>|null
     */
    private static function modelClass(mixed $type): ?string
    {
        if (! is_string($type) || ! array_key_exists($type, self::types())) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        return is_a($class, Model::class, true) && is_a($class, Billable::class, true) ? $class : null;
    }

    /**
     * @return array<string, string> morph class => label
     */
    private static function types(): array
    {
        return BillingPlugin::current()?->getBillableOptions() ?? [];
    }
}
