<?php

declare(strict_types=1);

namespace App\Filament\Resources\MenuItems\Tables;

use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Support\Money;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class MenuItemsTable
{
    public static function configure(Table $table): Table
    {
        $currency = Restaurant::current()->currency;

        return $table
            ->defaultGroup('category.name')
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (MenuItem $record): ?string => $record->description),

                TextColumn::make('price')
                    ->label('Price')
                    ->alignEnd()
                    ->sortable()
                    ->formatStateUsing(fn (int $state): string => Money::of($state, $currency)->format()),

                // The alias count, not the aliases: a dish with none is the
                // one the agent will mishear, and that is what this column is
                // for. The list itself is on the edit screen.
                TextColumn::make('spoken_aliases')
                    ->label('Aliases')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (MenuItem $record): string => $record->spoken_aliases === null || $record->spoken_aliases === []
                        ? 'warning'
                        : 'gray')
                    ->formatStateUsing(fn (MenuItem $record): string => (string) count($record->spoken_aliases ?? []))
                    ->tooltip(fn (MenuItem $record): string => $record->spoken_aliases === null || $record->spoken_aliases === []
                        ? 'No aliases. Callers who do not say the exact name will not be matched.'
                        : implode(', ', $record->spoken_aliases)),

                IconColumn::make('has_modifiers')
                    ->label('Options')
                    ->alignCenter()
                    ->boolean()
                    ->state(fn (MenuItem $record): bool => $record->modifier_groups_count > 0),

                ToggleColumn::make('is_available')
                    ->label('On the menu'),
            ])
            ->filters([
                SelectFilter::make('menu_category_id')
                    ->label('Category')
                    ->relationship('category', 'name')
                    ->preload(),

                TernaryFilter::make('is_available')
                    ->label('On the menu'),

                TernaryFilter::make('needs_aliases')
                    ->label('Missing aliases')
                    ->placeholder('All dishes')
                    ->trueLabel('Only dishes with no aliases')
                    ->falseLabel('Only dishes with aliases')
                    ->queries(
                        true: fn ($query) => $query->where(fn ($q) => $q->whereNull('spoken_aliases')->orWhereJsonLength('spoken_aliases', 0)),
                        false: fn ($query) => $query->whereJsonLength('spoken_aliases', '>', 0),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
