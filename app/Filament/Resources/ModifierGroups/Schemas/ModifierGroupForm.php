<?php

declare(strict_types=1);

namespace App\Filament\Resources\ModifierGroups\Schemas;

use App\Enums\ModifierGroupSelectionType;
use App\Enums\ModifierKind;
use App\Filament\Support\MoneyInput;
use App\Models\Restaurant;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * A group and its modifiers on one screen.
 *
 * They are separate tables but they are one idea — "how would you like it?"
 * and the answers you can give — and editing them apart means two navigation
 * items, two saves, and a group that spends a minute existing with no options
 * in it. The repeater keeps them together.
 */
class ModifierGroupForm
{
    public static function configure(Schema $schema): Schema
    {
        $restaurant = Restaurant::current();

        return $schema->components([
            Section::make('Group')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(120)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                            if ($get('slug') === null || $get('slug') === '') {
                                $set('slug', Str::slug((string) $state));
                            }
                        }),

                    TextInput::make('slug')
                        ->required()
                        ->maxLength(140),

                    TextInput::make('prompt')
                        ->label('What the agent asks')
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->placeholder('Which size would you like?')
                        ->helperText('Left blank, the agent improvises from the group name. Filling it in is how you control the wording.'),
                ]),

            Section::make('Rules')
                ->columns(2)
                ->description('How many of these the caller has to pick.')
                ->schema([
                    Select::make('selection_type')
                        ->label('Selection')
                        ->options(ModifierGroupSelectionType::class)
                        ->default(ModifierGroupSelectionType::Single)
                        ->required()
                        ->native(false)
                        ->live(),

                    Toggle::make('is_required')
                        ->label('Must be answered')
                        ->helperText('The agent will not let the order finish without it.'),

                    TextInput::make('min_selections')
                        ->label('Minimum')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required(),

                    TextInput::make('max_selections')
                        ->label('Maximum')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Blank means no limit.')
                        // A single-choice group has a maximum of one by
                        // definition; showing the field invites someone to
                        // contradict the selection type.
                        ->visible(fn (Get $get): bool => $get('selection_type') === ModifierGroupSelectionType::Multi->value
                            || $get('selection_type') === ModifierGroupSelectionType::Multi),
                ]),

            Section::make('Options')
                ->schema([
                    Repeater::make('modifiers')
                        ->hiddenLabel()
                        ->relationship()
                        ->orderColumn('sort_order')
                        ->addActionLabel('Add an option')
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                        ->collapsible()
                        ->cloneable()
                        ->defaultItems(1)
                        ->columns(2)
                        ->schema([
                            TextInput::make('name')
                                ->required()
                                ->maxLength(120)
                                ->live(onBlur: true)
                                ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                    if ($get('slug') === null || $get('slug') === '') {
                                        $set('slug', Str::slug((string) $state));
                                    }
                                }),

                            TextInput::make('slug')
                                ->required()
                                ->maxLength(140)
                                ->helperText('Unique across the whole menu — the agent names modifiers by slug.'),

                            Select::make('kind')
                                ->options(ModifierKind::class)
                                ->default(ModifierKind::Option)
                                ->required()
                                ->native(false),

                            MoneyInput::make('price_delta', $restaurant->currency)
                                ->label('Price change')
                                ->required()
                                ->default(0)
                                ->helperText('Added to the line. Negative is allowed.'),

                            TagsInput::make('spoken_aliases')
                                ->label('Also called')
                                ->columnSpanFull()
                                ->helperText('"no cheese", "hold the cheese", "without cheese" — all the same option.'),

                            Toggle::make('is_available')
                                ->label('Available')
                                ->default(true),

                            Toggle::make('is_default')
                                ->label('Chosen unless the caller says otherwise'),
                        ])
                        // Modifiers are tenant-scoped like everything else, and
                        // a repeater row has no idea which restaurant it
                        // belongs to. Filling it here keeps the NOT NULL happy
                        // and keeps the schema honest for the day this install
                        // has more than one restaurant in it.
                        ->mutateRelationshipDataBeforeCreateUsing(function (array $data) use ($restaurant): array {
                            $data['restaurant_id'] = $restaurant->id;

                            return $data;
                        }),
                ]),
        ]);
    }
}
