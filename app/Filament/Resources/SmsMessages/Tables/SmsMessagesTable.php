<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsMessages\Tables;

use App\Enums\SmsKind;
use App\Enums\SmsStatus;
use App\Models\SmsMessage;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SmsMessagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (SmsMessage $record): string => route(
                'filament.admin.resources.sms-messages.view',
                ['record' => $record],
            ))
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('j M, H:i')
                    ->sortable(),

                // Masked, because the useful bit is telling two customers
                // apart and the rest is personal data on a screen anyone
                // walking past can read. The full number is on the message.
                TextColumn::make('to_number')
                    ->label('To')
                    ->searchable()
                    ->state(fn (SmsMessage $record): string => $record->maskedNumber()),

                TextColumn::make('order.order_number')
                    ->label('Order')
                    ->placeholder('—')
                    ->searchable()
                    ->url(fn (SmsMessage $record): ?string => $record->order === null
                        ? null
                        : route('filament.admin.resources.orders.view', ['record' => $record->order])),

                TextColumn::make('kind')->label('Why')->badge()->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    // The provider's own words, under the badge. It is what
                    // decides whether the fix is resending or retyping a phone
                    // number, and it saves opening the row to find out.
                    ->description(fn (SmsMessage $record): ?string => $record->error),

                TextColumn::make('body')
                    ->label('Message')
                    ->limit(60)
                    ->toggleable()
                    ->searchable(),

                TextColumn::make('provider')
                    ->label('Sent via')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(SmsStatus::class)
                    ->multiple(),

                SelectFilter::make('kind')
                    ->label('Why')
                    ->options(SmsKind::class)
                    ->multiple(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No texts yet')
            ->emptyStateDescription('Confirming an order sends one. With SMS_DRIVER=log they still appear here, exactly as the customer would have received them.');
    }
}
