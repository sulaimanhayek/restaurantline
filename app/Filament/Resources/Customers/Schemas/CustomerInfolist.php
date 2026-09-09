<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Address;
use App\Models\Customer;
use App\Models\Restaurant;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $currency = Restaurant::current()->currency;

        return $schema->components([
            Grid::make(4)->schema([
                TextEntry::make('name')
                    ->placeholder('Not given'),

                TextEntry::make('phone_number')
                    ->label('Phone')
                    ->copyable(),

                TextEntry::make('order_count')
                    ->label('Orders'),

                TextEntry::make('last_ordered_at')
                    ->label('Last ordered')
                    ->since()
                    ->placeholder('Never'),
            ]),

            Section::make('Notes')
                ->schema([
                    TextEntry::make('notes')
                        ->hiddenLabel()
                        ->placeholder('Nothing recorded.'),
                ]),

            Section::make('Addresses')
                ->schema([
                    RepeatableEntry::make('addresses')
                        ->hiddenLabel()
                        ->schema([
                            Grid::make(3)->schema([
                                TextEntry::make('formatted_address')
                                    ->hiddenLabel()
                                    ->columnSpan(2)
                                    // The verbatim words matter more than the
                                    // tidy version when a driver is lost.
                                    ->helperText(fn (Address $record): ?string => $record->raw_spoken_text),

                                TextEntry::make('verified_at')
                                    ->hiddenLabel()
                                    ->badge()
                                    ->color(fn (Address $record): string => $record->isVerified() ? 'success' : 'danger')
                                    ->formatStateUsing(fn (Address $record): string => $record->isVerified()
                                        ? 'Confirmed aloud'
                                        : 'Not confirmed')
                                    ->state(fn (Address $record): string => $record->isVerified() ? 'yes' : 'no'),
                            ]),
                        ]),
                ]),

            Section::make('Orders')
                ->schema([
                    RepeatableEntry::make('orders')
                        ->hiddenLabel()
                        ->schema([
                            Grid::make(4)->schema([
                                TextEntry::make('order_number')
                                    ->hiddenLabel(),

                                TextEntry::make('created_at')
                                    ->hiddenLabel()
                                    ->dateTime('j M Y, H:i'),

                                TextEntry::make('status')
                                    ->hiddenLabel()
                                    ->badge(),

                                TextEntry::make('total')
                                    ->hiddenLabel()
                                    ->alignEnd()
                                    ->formatStateUsing(fn (int $state): string => Money::of($state, $currency)->format()),
                            ]),
                        ]),
                ])
                ->visible(fn (Customer $record): bool => $record->orders->isNotEmpty()),
        ]);
    }
}
