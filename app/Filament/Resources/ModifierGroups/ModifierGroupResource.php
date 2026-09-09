<?php

declare(strict_types=1);

namespace App\Filament\Resources\ModifierGroups;

use App\Filament\Concerns\ScopesToCurrentRestaurant;
use App\Filament\Resources\ModifierGroups\Pages\CreateModifierGroup;
use App\Filament\Resources\ModifierGroups\Pages\EditModifierGroup;
use App\Filament\Resources\ModifierGroups\Pages\ListModifierGroups;
use App\Filament\Resources\ModifierGroups\Schemas\ModifierGroupForm;
use App\Filament\Resources\ModifierGroups\Tables\ModifierGroupsTable;
use App\Models\ModifierGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Sizes, sides, extras and the things a caller asks to leave out.
 *
 * @see docs/DECISIONS.md #0018 for why modifiers are their own tables rather
 *      than a JSON blob on the item.
 *
 * The `@extends` tag is fully qualified because Pint's phpdoc_types fixer
 * lowercases a bare `Resource` — PHP has a native `resource` type and the
 * fixer cannot tell the two apart. The leading backslash is what stops it.
 *
 * @extends \Filament\Resources\Resource<ModifierGroup>
 */
class ModifierGroupResource extends Resource
{
    /** @use ScopesToCurrentRestaurant<ModifierGroup> */
    use ScopesToCurrentRestaurant;

    protected static ?string $model = ModifierGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Menu';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'modifier group';

    /**
     * @param  Builder<ModifierGroup>  $query
     * @return Builder<ModifierGroup>
     */
    protected static function modifyScopedQuery(Builder $query): Builder
    {
        return $query->withCount(['modifiers', 'menuItems']);
    }

    public static function form(Schema $schema): Schema
    {
        return ModifierGroupForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ModifierGroupsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModifierGroups::route('/'),
            'create' => CreateModifierGroup::route('/create'),
            'edit' => EditModifierGroup::route('/{record}/edit'),
        ];
    }
}
