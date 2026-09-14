<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsMessages\Schemas;

use App\Models\SmsMessage;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * One text, as it went out.
 *
 * The body is shown verbatim rather than re-rendered from the order, because
 * the question being asked is what the customer actually received — an order
 * edited since is exactly the case where a regenerated message would lie.
 */
class SmsMessageInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The message')
                ->schema([
                    TextEntry::make('body')
                        ->hiddenLabel()
                        ->copyable()
                        ->copyMessage('Copied — paste it to the customer.'),
                ]),

            Section::make('What happened to it')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('kind')->label('Why')->badge(),
                        TextEntry::make('sent_at')
                            ->label('Accepted by provider')
                            ->dateTime()
                            ->placeholder('—')
                            // `sent` means a provider took it, not that a
                            // handset rang. Saying so here stops this screen
                            // being read as proof of delivery in an argument.
                            ->helperText('The provider took it. Delivery to the handset is not something this application can see.'),

                        TextEntry::make('provider')->label('Sent via')->badge()->color('gray')->placeholder('—'),
                        TextEntry::make('provider_message_id')
                            ->label('Provider reference')
                            ->placeholder('—')
                            ->copyable()
                            ->helperText('Quote this when asking the provider what became of it.'),
                        TextEntry::make('created_at')->label('Written down')->dateTime(),
                    ]),

                    TextEntry::make('error')
                        ->label('The provider refused it')
                        ->color('danger')
                        ->visible(fn (SmsMessage $record): bool => filled($record->error)),
                ]),

            Section::make('Who it went to')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('to_number')
                            ->label('Number')
                            ->copyable()
                            // The number as it was sent, not the customer's
                            // number as it is now. They diverge the moment
                            // somebody corrects a typo, and this is the one
                            // that explains where the text went.
                            ->helperText('The number this text was sent to, which is not necessarily the customer\'s number today.'),

                        TextEntry::make('customer.name')
                            ->label('Customer')
                            ->placeholder('—')
                            ->url(fn (SmsMessage $record): ?string => $record->customer === null
                                ? null
                                : route('filament.admin.resources.customers.view', ['record' => $record->customer])),

                        TextEntry::make('order.order_number')
                            ->label('Order')
                            ->placeholder('—')
                            ->url(fn (SmsMessage $record): ?string => $record->order === null
                                ? null
                                : route('filament.admin.resources.orders.view', ['record' => $record->order])),
                    ]),
                ]),
        ]);
    }
}
