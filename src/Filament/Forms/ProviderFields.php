<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Forms;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Ivanange\Billing\Gateways\SettingField;

/**
 * Turns a provider's plain SettingField descriptions into Filament fields.
 */
final class ProviderFields
{
    /**
     * @param  list<SettingField>  $fields
     * @return list<Field>
     */
    public static function make(array $fields, string $prefix, bool $secretsKeepExisting = false): array
    {
        return array_map(function (SettingField $field) use ($prefix, $secretsKeepExisting): Field {
            $name = "{$prefix}.{$field->name}";

            $component = match ($field->type) {
                SettingField::TEXTAREA => Textarea::make($name)->rows(3),
                SettingField::TOGGLE => Toggle::make($name)->default((bool) $field->default),
                SettingField::SECRET => TextInput::make($name)->password()->revealable(false)->autocomplete('off')
                    ->placeholder($secretsKeepExisting ? __('billing::billing.ui.gateway.secret_unchanged') : null),
                default => TextInput::make($name),
            };

            return $component
                ->label($field->label())
                ->helperText($field->help())
                ->required($field->required && ! ($secretsKeepExisting && $field->type === SettingField::SECRET));
        }, $fields);
    }
}
