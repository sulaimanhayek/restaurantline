<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Models\Order;
use App\Models\Restaurant;
use App\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\HtmlString;

/**
 * One order, read the way somebody reads it back to an annoyed caller.
 *
 * The order of the sections is the order the questions come in: what did they
 * order, where is it going, has it been paid for, and when did all this happen.
 * The transcript link at the bottom is the one that matters when the answer is
 * "that isn't what I asked for" — see the conversation review screen.
 */
class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $currency = Restaurant::current()->currency;

        return $schema->components([
            Section::make()
                ->schema([
                    Grid::make(4)->schema([
                        TextEntry::make('order_number')
                            ->label('Order')
                            ->weight('bold')
                            ->size(TextSize::Large)
                            ->copyable(),

                        TextEntry::make('status')->badge(),

                        TextEntry::make('fulfilment_type')
                            ->label('Type')
                            ->badge()
                            ->color('gray'),

                        TextEntry::make('source')
                            ->badge()
                            ->color('gray'),
                    ]),
                ]),

            Section::make('Items')
                ->schema([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->schema([
                            Grid::make(12)->schema([
                                TextEntry::make('quantity')
                                    ->hiddenLabel()
                                    ->columnSpan(1)
                                    ->formatStateUsing(fn (int $state): string => $state.'×')
                                    ->weight('bold'),

                                TextEntry::make('name')
                                    ->hiddenLabel()
                                    ->columnSpan(8)
                                    /*
                                     * The modifiers are rendered from the
                                     * snapshot on the line, never from the
                                     * live modifier rows. A price that changed
                                     * last week must not silently rewrite what
                                     * a caller agreed to on Friday.
                                     */
                                    ->helperText(fn ($record): ?HtmlString => self::modifierLines($record, $currency)),

                                TextEntry::make('line_total')
                                    ->hiddenLabel()
                                    ->columnSpan(3)
                                    ->alignEnd()
                                    ->money($currency, divideBy: 100),
                            ]),
                        ])
                        ->contained(false),
                ]),

            Section::make('Totals')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('subtotal')->money($currency, divideBy: 100),
                        TextEntry::make('delivery_fee')
                            ->label('Delivery')
                            ->money($currency, divideBy: 100)
                            ->placeholder('—'),
                        TextEntry::make('total')
                            ->money($currency, divideBy: 100)
                            ->weight('bold')
                            ->size(TextSize::Large),
                    ]),
                ]),

            Section::make('Delivery address')
                ->visible(fn (Order $record): bool => $record->isDelivery() && $record->address !== null)
                ->schema([
                    TextEntry::make('address.formatted_address')
                        ->label('Address')
                        ->placeholder('—'),

                    /*
                     * The caller's own words, kept verbatim and never
                     * overwritten by the geocoder's tidier version. When a
                     * driver cannot find a door, this line is what explains
                     * why — it is the debugging goldmine the schema comment
                     * promises, and it is useless if it is not on screen.
                     */
                    TextEntry::make('address.raw_spoken_text')
                        ->label('As the caller said it')
                        ->placeholder('—')
                        ->color('gray'),

                    Grid::make(3)->schema([
                        TextEntry::make('address.verified_at')
                            ->label('Read back to caller')
                            ->badge()
                            ->color(fn (?string $state): string => $state === null ? 'danger' : 'success')
                            ->formatStateUsing(fn (?string $state): string => $state === null ? 'Not confirmed' : 'Confirmed aloud'),

                        TextEntry::make('address.distance_metres')
                            ->label('Distance')
                            ->placeholder('—')
                            ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : number_format($state).' m'),

                        TextEntry::make('address.geocode_confidence')
                            ->label('Geocoder confidence')
                            ->placeholder('—'),
                    ]),
                ]),

            Section::make('Payment')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('payment_status')->label('Status')->badge(),
                        TextEntry::make('payment_reference')->label('Reference')->placeholder('—'),
                        TextEntry::make('payment_link_url')
                            ->label('Payment link')
                            ->placeholder('—')
                            ->url(fn (?string $state): ?string => $state)
                            ->openUrlInNewTab(),
                    ]),
                ])
                /*
                 * No card fields here, and there never will be. Payment is a
                 * link sent after the call or cash on the door — the voice line
                 * never touches a card number, and neither does this screen.
                 * See the README's payment section.
                 */
                ->description('Card details are never taken over the phone. This is a link or cash on delivery.'),

            Section::make('Timings')
                ->collapsed()
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('created_at')->label('Taken')->dateTime(),
                        TextEntry::make('confirmed_at')->label('Confirmed')->dateTime()->placeholder('—'),
                        TextEntry::make('estimated_ready_at')->label('Estimated ready')->dateTime()->placeholder('—'),
                        TextEntry::make('accepted_at')->label('Accepted')->dateTime()->placeholder('—'),
                        TextEntry::make('ready_at')->label('Ready')->dateTime()->placeholder('—'),
                        TextEntry::make('completed_at')->label('Completed')->dateTime()->placeholder('—'),
                    ]),
                    TextEntry::make('cancellation_reason')
                        ->label('Cancelled because')
                        ->visible(fn (Order $record): bool => $record->cancelled_at !== null)
                        ->color('danger'),
                ]),

            Section::make('Notes')
                ->visible(fn (Order $record): bool => filled($record->notes))
                ->schema([
                    TextEntry::make('notes')->hiddenLabel(),
                ]),

            Section::make('The call')
                ->visible(fn (Order $record): bool => $record->conversation !== null)
                ->schema([
                    TextEntry::make('conversation.elevenlabs_conversation_id')
                        ->label('Conversation')
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('conversation.outcome')->label('Outcome')->badge(),
                    TextEntry::make('conversation.caller_number')->label('Caller')->placeholder('withheld'),
                ]),
        ]);
    }

    /**
     * The modifiers on one line, rendered from its snapshot.
     *
     * Returns null rather than an empty string when there are none, so Filament
     * omits the helper text entirely instead of leaving a blank row.
     */
    private static function modifierLines(mixed $record, string $currency): ?HtmlString
    {
        $modifiers = $record->modifiers_snapshot ?? [];

        if (! is_array($modifiers) || $modifiers === []) {
            return null;
        }

        $lines = [];

        foreach ($modifiers as $modifier) {
            if (! is_array($modifier)) {
                continue;
            }

            $name = is_string($modifier['name'] ?? null) ? $modifier['name'] : 'Unnamed';
            $delta = is_int($modifier['price_delta'] ?? null) ? $modifier['price_delta'] : 0;

            // A removal reads as "no onions", not "onions +£0.00". The kind is
            // what carries that, and it is in the snapshot for this reason.
            $prefix = ($modifier['kind'] ?? null) === 'removal' ? 'no ' : '';

            $lines[] = $delta === 0
                ? $prefix.$name
                : sprintf('%s%s (%s%s)', $prefix, $name, $delta > 0 ? '+' : '−', Money::of(abs($delta), $currency)->format());
        }

        return $lines === [] ? null : new HtmlString(e(implode(' · ', $lines)));
    }
}
