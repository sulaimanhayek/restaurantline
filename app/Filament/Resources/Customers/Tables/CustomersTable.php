<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Tables;

use App\Models\Customer;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('last_ordered_at', 'desc')
            ->columns([
                TextColumn::make('phone_number')
                    ->label('Phone')
                    ->searchable()
                    ->copyable()
                    ->description(fn (Customer $record): ?string => $record->name),

                TextColumn::make('name')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('order_count')
                    ->label('Orders')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('first_ordered_at')
                    ->label('First order')
                    ->date('j M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('last_ordered_at')
                    ->label('Last order')
                    ->since()
                    ->sortable()
                    ->placeholder('Never'),

                TextColumn::make('notes')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('returning')
                    ->label('Returning customers only')
                    ->query(fn (Builder $query): Builder => $query->where('order_count', '>', 1)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            // No bulk delete. A customer row is the only thing tying a phone
            // number to the orders taken from it, and deleting one turns a
            // billing question into an unanswerable one.
            ->toolbarActions([]);
    }
}
