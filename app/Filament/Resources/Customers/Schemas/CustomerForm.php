<?php

declare(strict_types=1);

namespace App\Filament\Resources\Customers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Two fields, both of them things a person learns and the agent cannot.
 *
 * A customer record is otherwise assembled from calls: the phone number is the
 * caller ID, the counts and dates come from orders. Editing those by hand would
 * only make them wrong.
 */
class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->schema([
                    TextInput::make('name')
                        ->maxLength(120)
                        ->helperText('The agent greets returning callers by name when this is set.'),

                    Textarea::make('notes')
                        ->rows(4)
                        ->maxLength(2000)
                        ->helperText('Read by staff, not by the agent. Allergies, buzzer codes, the dog.'),
                ]),
        ]);
    }
}
