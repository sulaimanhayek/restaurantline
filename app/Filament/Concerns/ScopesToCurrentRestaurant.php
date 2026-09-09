<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Constrains a resource to the restaurant this install belongs to.
 *
 * Today `Restaurant::current()` returns the one seeded row and this filter
 * changes nothing. It is here because the schema was built multi-tenant from
 * the first migration (docs/DECISIONS.md #0005), and the expensive version of
 * that decision is the one where the tables are ready but the dashboard reads
 * across all of them. Adding the `where` on the day a second restaurant
 * appears means auditing every resource under deadline; adding it now means
 * one line per resource and nothing to remember.
 *
 * A resource that needs eager loading or counts overrides
 * `modifyScopedQuery()` rather than `getEloquentQuery()`, so the tenant filter
 * cannot be dropped by an override that forgot about it.
 *
 * The trait is generic so that override can name its own model, which means a
 * resource using it states its model twice: `@extends Resource<Order>` on the
 * class docblock types the parent query, and `@use ScopesToCurrentRestaurant<Order>`
 * on the `use` statement itself types this one. Miss either and PHPStan
 * reports the variance error here rather than in the resource, because
 * `Builder` is invariant in its model.
 *
 * @template TModel of Model
 */
trait ScopesToCurrentRestaurant
{
    /**
     * @return Builder<TModel>
     */
    public static function getEloquentQuery(): Builder
    {
        return static::modifyScopedQuery(
            parent::getEloquentQuery()->whereBelongsTo(Restaurant::current()),
        );
    }

    /**
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected static function modifyScopedQuery(Builder $query): Builder
    {
        return $query;
    }
}
