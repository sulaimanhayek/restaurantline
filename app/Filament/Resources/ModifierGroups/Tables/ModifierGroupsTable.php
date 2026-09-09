<?php

declare(strict_types=1);

namespace App\Filament\Resources\ModifierGroups\Tables;

use App\Models\ModifierGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ModifierGroupsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (ModifierGroup $record): ?string => $record->prompt),

                TextColumn::make('selection_type')
                    ->label('Selection')
                    ->badge(),

                IconColumn::make('is_required')
                    ->label('Required')
                    ->alignCenter()
                    ->boolean(),

                TextColumn::make('modifiers_count')
                    ->label('Options')
                    ->alignCenter()
                    ->badge()
                    ->color('gray'),

                TextColumn::make('menu_items_count')
                    ->label('Used on')
                    ->alignCenter()
                    ->badge()
                    ->color(fn (int $state): string => $state === 0 ? 'warning' : 'gray')
                    ->formatStateUsing(fn (int $state): string => $state === 1 ? '1 dish' : $state.' dishes')
                    ->tooltip(fn (int $state): ?string => $state === 0
                        ? 'Not attached to any dish, so no caller will ever be asked this.'
                        : null),
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
