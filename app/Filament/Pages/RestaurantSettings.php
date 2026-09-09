<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\MoneyInput;
use App\Models\Restaurant;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The restaurant's own settings.
 *
 * A page rather than a resource, because there is one row and always will be
 * on a given install — a list view with a single item in it, and a "New
 * restaurant" button that must not be pressed, is a worse version of this.
 *
 * The fields here are the ones the agent reads at runtime. Changing the
 * delivery radius or the prep time changes what the next caller is told, with
 * no deploy and no provisioning run, which is the point.
 *
 * @property-read Schema $form
 *
 * @see docs/DECISIONS.md #0028
 */
class RestaurantSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 40;

    protected static ?string $title = 'Restaurant';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public Restaurant $restaurant;

    public function mount(): void
    {
        $this->restaurant = Restaurant::current();

        $this->form->fill($this->restaurant->attributesToArray());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->model($this->restaurant)
            ->statePath('data')
            ->components([
                Section::make('The restaurant')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(120),

                        TextInput::make('phone_number')
                            ->label('Phone number')
                            ->tel()
                            ->maxLength(32)
                            ->helperText('The number callers dial. Set by provisioning; shown here so you can check it.'),

                        TextInput::make('transfer_phone_number')
                            ->label('Human fallback number')
                            ->tel()
                            ->maxLength(32)
                            // The escalate tool transfers here. Without it the
                            // agent can offer a human and then fail to produce
                            // one, which is worse than never offering.
                            ->helperText('Where the agent transfers a caller who asks for a person. Leave this blank and "let me put you through" has nowhere to go.'),

                        TextInput::make('email')
                            ->email()
                            ->maxLength(160),

                        Select::make('timezone')
                            ->options(array_combine(
                                DateTimeZone::listIdentifiers(),
                                DateTimeZone::listIdentifiers(),
                            ))
                            ->searchable()
                            ->required()
                            ->helperText('Opening hours and prep times are read in this zone. Everything is stored in UTC.'),

                        TextInput::make('currency')
                            ->required()
                            ->maxLength(3)
                            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                            ->helperText('ISO 4217, e.g. GBP.'),
                    ]),

                Section::make('Where you are')
                    ->columns(2)
                    ->description('The origin for every delivery distance, so it is worth getting the coordinates right rather than approximately right.')
                    ->schema([
                        TextInput::make('address_line_1')->label('Address line 1')->maxLength(160),
                        TextInput::make('address_line_2')->label('Address line 2')->maxLength(160),
                        TextInput::make('city')->maxLength(120),
                        TextInput::make('postcode')->maxLength(16),
                        TextInput::make('country')->maxLength(2)->helperText('Two-letter code, e.g. GB.'),
                        TextInput::make('latitude')->numeric()->step('0.0000001'),
                        TextInput::make('longitude')->numeric()->step('0.0000001'),
                    ]),

                Section::make('Taking orders')
                    ->columns(2)
                    ->schema([
                        Toggle::make('is_accepting_orders')
                            ->label('Accepting orders')
                            ->columnSpanFull()
                            ->helperText('Turn this off and the agent still answers, apologises, and takes no orders. Use it when the kitchen is swamped.'),

                        TextInput::make('delivery_radius_metres')
                            ->label('Delivery radius')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->suffix('metres')
                            ->helperText('Straight-line, not driving distance. Callers outside it are offered collection.'),

                        MoneyInput::make('minimum_order_value', $this->restaurantCurrency())
                            ->label('Minimum delivery order')
                            ->required(),

                        MoneyInput::make('base_delivery_fee', $this->restaurantCurrency())
                            ->label('Base delivery fee')
                            ->required()
                            ->helperText('Distance bands can override this.'),

                        TextInput::make('collection_prep_minutes')
                            ->label('Collection ready in')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->suffix('minutes'),

                        TextInput::make('delivery_prep_minutes')
                            ->label('Delivery arrives in')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            ->suffix('minutes'),
                    ]),

                Section::make('The agent')
                    ->description('Wording the agent uses. Changing it here takes effect on the next call; it does not need a redeploy.')
                    ->schema([
                        Textarea::make('agent_greeting')
                            ->label('Greeting')
                            ->rows(2)
                            ->maxLength(500)
                            ->placeholder('Ember Grill, how can I help?'),

                        Textarea::make('agent_tone_of_voice')
                            ->label('Tone of voice')
                            ->rows(3)
                            ->maxLength(1000)
                            ->helperText('A sentence or two of direction — "warm, quick, never upsell". This is prompt text, so write it the way you would brief a new member of staff.'),

                        TextInput::make('elevenlabs_agent_id')
                            ->label('ElevenLabs agent ID')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Not provisioned yet')
                            ->helperText('Written by the provisioning command. Read-only here on purpose — editing it points this install at somebody else\'s agent.'),
                    ]),
            ]);
    }

    public function save(): void
    {
        $this->restaurant->update($this->form->getState());

        Notification::make()
            ->title('Saved')
            ->body('The next call uses these settings.')
            ->success()
            ->send();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
        ]);
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label('Save')
                        ->submit('save')
                        ->keyBindings(['mod+s']),
                ])
                    ->sticky()
                    ->key('form-actions'),
            ]);
    }

    /**
     * Read from the saved row rather than from form state: the prefix on a
     * money field is decided when the schema is built, and reading a
     * half-typed currency code out of the form would produce a field labelled
     * "G".
     */
    private function restaurantCurrency(): string
    {
        return $this->restaurant->currency;
    }
}
