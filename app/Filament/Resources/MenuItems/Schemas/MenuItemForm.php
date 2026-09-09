<?php

declare(strict_types=1);

namespace App\Filament\Resources\MenuItems\Schemas;

use App\Filament\Support\MoneyInput;
use App\Models\Restaurant;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class MenuItemForm
{
    public static function configure(Schema $schema): Schema
    {
        $restaurant = Restaurant::current();

        return $schema->components([
            Section::make('Dish')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(120)
                        ->live(onBlur: true)
                        // Only fill the slug while it is still untouched. Once
                        // an item exists, its slug is what the agent's tool
                        // calls refer to, and renaming a dish must not silently
                        // break them.
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                            if ($get('slug') === null || $get('slug') === '') {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),

                    Select::make('menu_category_id')
                        ->label('Category')
                        ->relationship('category', 'name')
                        ->required()
                        ->native(false)
                        ->preload(),

                    Textarea::make('description')
                        ->rows(2)
                        ->maxLength(500)
                        ->columnSpanFull()
                        ->helperText('The agent reads this out when a caller asks what is in something.'),

                    MoneyInput::make('price', $restaurant->currency)
                        ->label('Price')
                        ->required(),

                    TextInput::make('prep_minutes')
                        ->label('Prep time')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(240)
                        ->suffix('minutes')
                        ->helperText('Blank uses the restaurant default.'),

                    TextInput::make('slug')
                        ->required()
                        ->maxLength(140)
                        ->helperText('Used by the agent and by menu imports. Changing it can break both.'),

                    TextInput::make('sku')
                        ->label('SKU')
                        ->maxLength(64)
                        ->helperText('Optional. Yours to match against a till or spreadsheet.'),
                ]),

            Section::make('How the agent hears it')
                ->description('A caller says "the big chicken one", not "Half Peri Chicken". Every alias you add here is a phrase the matcher will accept.')
                ->schema([
                    TagsInput::make('spoken_aliases')
                        ->label('Also called')
                        ->placeholder('Add a phrase and press enter')
                        ->helperText('Include the abbreviations, the mispronunciations and the regional names. This is the single highest-value field on the page for order accuracy.'),
                ]),

            Section::make('Options')
                ->description('Choices the caller is offered for this dish — sizes, sides, things to leave out.')
                ->schema([
                    Select::make('modifierGroups')
                        ->label('Modifier groups')
                        ->relationship('modifierGroups', 'name')
                        ->multiple()
                        ->preload()
                        ->native(false),
                ]),

            Section::make('Availability')
                ->schema([
                    Toggle::make('is_available')
                        ->label('On the menu')
                        ->default(true)
                        ->helperText('Turn this off when you run out. The agent stops offering it immediately and tells callers it is sold out.'),
                ]),
        ]);
    }
}
