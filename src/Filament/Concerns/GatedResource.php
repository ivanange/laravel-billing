<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Ivanange\Billing\Filament\BillingPlugin;

/**
 * Resource access follows the billing.* Gate abilities, so apps control it
 * with their own roles instead of model policies.
 */
trait GatedResource
{
    abstract protected static function ability(): string;

    public static function canViewAny(): bool
    {
        return Gate::allows(static::ability()) || Gate::allows('billing.manage');
    }

    public static function canCreate(): bool
    {
        return Gate::allows('billing.manage');
    }

    public static function canEdit(Model $record): bool
    {
        return Gate::allows('billing.manage');
    }

    public static function canDelete(Model $record): bool
    {
        return Gate::allows('billing.manage');
    }

    public static function canDeleteAny(): bool
    {
        return Gate::allows('billing.manage');
    }

    public static function getNavigationGroup(): ?string
    {
        return BillingPlugin::get()->getNavigationGroup();
    }
}
