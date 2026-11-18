<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Forms;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Resources\Resource as FilamentResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Ivanange\Billing\Contracts\Billable;
use Ivanange\Billing\Filament\BillingPlugin;
use LogicException;

/**
 * Finds the records the payer picker may offer: limited to the current
 * Filament tenant and to BillingPlugin::payerQueryUsing(), and each one
 * allowed by the billing.record ability. Search and resolve share these
 * rules, so a tampered submit cannot name a record the search hides.
 */
final class PayerQuery
{
    private const LIKE_ESCAPE = '!';

    /**
     * Column name => type name per connection and table: the schema does not
     * change while the process runs, and search runs on every keystroke.
     *
     * @var array<string, array<string, string>>
     */
    private static array $columns = [];

    /**
     * @param  class-string<Model&Billable>  $class
     * @return array<int|string, string> key => billing name
     */
    public static function search(string $class, string $term, int $limit): array
    {
        $query = self::query($class);

        if ($query === null) {
            return [];
        }

        $model = $query->getModel();
        $columns = BillingPlugin::current()?->getBillableSearchColumns($class)
            ?? (array_key_exists('name', self::columns($model)) ? ['name'] : []);
        $key = self::key($model, $term);

        if ($columns === [] && $key === null) {
            return [];
        }

        $payers = $query
            ->where(function (Builder $query) use ($columns, $term, $key, $model): void {
                foreach ($columns as $column) {
                    self::orWhereContains($query, $model->qualifyColumn($column), $term);
                }

                if ($key !== null) {
                    $query->orWhere($model->getQualifiedKeyName(), $key);
                }
            })
            ->limit($limit)
            ->get();

        $options = [];
        foreach ($payers as $payer) {
            if ($payer instanceof Billable && self::allowed($payer, $class)) {
                $options[$payer->getKey()] = $payer->billingName();
            }
        }

        return $options;
    }

    /**
     * @param  class-string<Model&Billable>  $class
     */
    public static function find(string $class, mixed $id): (Model&Billable)|null
    {
        $query = is_int($id) || is_string($id) ? self::query($class) : null;
        $key = $query === null ? null : self::key($query->getModel(), (string) $id);

        if ($query === null || $key === null) {
            return null;
        }

        $payer = $query->whereKey($key)->first();

        return $payer instanceof Billable && self::allowed($payer, $class) ? $payer : null;
    }

    /**
     * The records that may be chosen, or null when none may.
     *
     * @param  class-string<Model&Billable>  $class
     * @return Builder<Model>|null
     */
    private static function query(string $class): ?Builder
    {
        $panel = BillingPlugin::currentPanel();
        $resource = $panel?->getModelResource($class);
        $query = $resource !== null ? $resource::getEloquentQuery() : $class::query();
        $scoped = $panel === null ? $query : self::scopeToTenant($query, $panel, $resource);
        $callback = BillingPlugin::current()?->getPayerQueryUsing();

        if ($callback === null) {
            return $scoped;
        }

        // The app's callback decides alone where no tenant scope can be found.
        $result = $callback($scoped ?? $query, $class) ?? $scoped ?? $query;

        if (! $result instanceof Builder || ! $result->getModel() instanceof $class) {
            throw new LogicException("payerQueryUsing() must return a query for [{$class}].");
        }

        return $result;
    }

    /**
     * The scope Filament applies to a resource's records in a tenant panel. A
     * payer model with a resource on the panel follows that resource (which
     * may opt out with scopeToTenant(false)); one without a resource needs the
     * panel's ownership relationship, and without it nobody can be chosen.
     *
     * @param  Builder<Model>  $query
     * @param  class-string<FilamentResource>|null  $resource
     * @return Builder<Model>|null
     */
    private static function scopeToTenant(Builder $query, Panel $panel, ?string $resource): ?Builder
    {
        $tenant = $panel->hasTenancy() ? Filament::getTenant() : null;

        if ($tenant === null) {
            return $query;
        }

        if ($resource !== null) {
            return $resource::isScopedToTenant() ? $resource::scopeEloquentQueryToTenant($query, $tenant) : $query;
        }

        $model = $query->getModel();

        if ($model::class === $tenant::class) {
            return $query->whereKey($tenant->getKey());
        }

        $name = $panel->getTenantOwnershipRelationshipName();

        if ($model->hasAttribute($name) || ! $model->isRelation($name)) {
            return null;
        }

        $relation = $model->{$name}();

        return match (true) {
            $relation instanceof MorphTo => $query->whereMorphedTo($name, $tenant),
            $relation instanceof BelongsTo => $query->whereBelongsTo($tenant, $name),
            default => $query->whereHas($name, fn (Builder $owner) => $owner->whereKey($tenant->getKey())),
        };
    }

    /**
     * @param  Builder<Model>  $query
     */
    private static function orWhereContains(Builder $query, string $column, string $term): void
    {
        $escape = self::LIKE_ESCAPE;
        $pattern = '%'.str_replace([$escape, '%', '_'], [$escape.$escape, $escape.'%', $escape.'_'], mb_strtolower($term)).'%';
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        // Plain LIKE is case-sensitive on PostgreSQL; SQLite has no default
        // escape character, so name one everywhere.
        $sql = $query->getModel()->getConnection()->getDriverName() === 'pgsql'
            ? "{$wrapped}::text ilike ? escape '{$escape}'"
            : "lower({$wrapped}) like ? escape '{$escape}'";

        $query->orWhereRaw($sql, [$pattern]);
    }

    /**
     * The value as this model's key, or null when it cannot be one. Comparing
     * a key column with a value of the wrong kind is an error on PostgreSQL
     * (a uuid column against "123", an integer one beyond its range).
     */
    private static function key(Model $model, string $value): int|string|null
    {
        $value = trim($value);

        if (in_array($model->getKeyType(), ['int', 'integer'], true)) {
            return self::integerKey($value, self::columns($model)[$model->getKeyName()] ?? '');
        }

        $traits = class_uses_recursive($model);

        if (in_array(HasUuids::class, $traits, true) || (self::columns($model)[$model->getKeyName()] ?? '') === 'uuid') {
            return Str::isUuid($value) ? strtolower($value) : null;
        }

        if (in_array(HasUlids::class, $traits, true)) {
            return Str::isUlid($value) ? strtolower($value) : null;
        }

        return $value === '' ? null : $value;
    }

    private static function integerKey(string $value, string $columnType): ?int
    {
        if (! ctype_digit($value)) {
            return null;
        }

        $key = filter_var(ltrim($value, '0') ?: '0', FILTER_VALIDATE_INT);
        // Only PostgreSQL fails on an out-of-range value; elsewhere it just
        // matches nothing. SQLite's "integer" holds 64 bits.
        $max = match ($columnType) {
            'int2', 'smallint' => 32767,
            'int4', 'int' => 2147483647,
            default => PHP_INT_MAX,
        };

        return is_int($key) && $key <= $max ? $key : null;
    }

    private static function allowed(Model $payer, string $class): bool
    {
        return $payer instanceof $class && Gate::allows('billing.record', [$payer]);
    }

    /**
     * @return array<string, string> column name => lower-case type name
     */
    private static function columns(Model $model): array
    {
        $connection = $model->getConnection();
        $cacheKey = $connection->getName().'|'.$connection->getTablePrefix().$model->getTable();

        if (! isset(self::$columns[$cacheKey])) {
            $columns = [];
            foreach (Schema::connection($connection->getName())->getColumns($model->getTable()) as $column) {
                $columns[(string) $column['name']] = strtolower((string) $column['type_name']);
            }
            self::$columns[$cacheKey] = $columns;
        }

        return self::$columns[$cacheKey];
    }
}
