<?php

declare(strict_types=1);

namespace App\Filament\Resources\MenuItems;

use App\Filament\Concerns\ScopesToCurrentRestaurant;
use App\Filament\Resources\MenuItems\Pages\CreateMenuItem;
use App\Filament\Resources\MenuItems\Pages\EditMenuItem;
use App\Filament\Resources\MenuItems\Pages\ListMenuItems;
use App\Filament\Resources\MenuItems\Schemas\MenuItemForm;
use App\Filament\Resources\MenuItems\Tables\MenuItemsTable;
use App\Models\MenuItem;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * The menu the agent sells from.
 *
 * Everything here is edited by hand, unlike orders. The one field worth
 * labouring over is `spoken_aliases` — it is what turns "the big chicken one"
 * into a line on an order, and a menu without it produces a polite agent that
 * cannot take an order.
 *
 * The `@extends` tag is fully qualified because Pint's phpdoc_types fixer
 * lowercases a bare `Resource` — PHP has a native `resource` type and the
 * fixer cannot tell the two apart. The leading backslash is what stops it.
 *
 * @extends \Filament\Resources\Resource<MenuItem>
 */
class MenuItemResource extends Resource
{
    /** @use ScopesToCurrentRestaurant<MenuItem> */
    use ScopesToCurrentRestaurant;

    protected static ?string $model = MenuItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = 'Menu';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * @param  Builder<MenuItem>  $query
     * @return Builder<MenuItem>
     */
    protected static function modifyScopedQuery(Builder $query): Builder
    {
        return $query->with('category')->withCount('modifierGroups');
    }

    public static function form(Schema $schema): Schema
    {
        return MenuItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MenuItemsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenuItems::route('/'),
            'create' => CreateMenuItem::route('/create'),
            'edit' => EditMenuItem::route('/{record}/edit'),
        ];
    }
}
