<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conversations\Tables;

use App\Enums\ConversationOutcome;
use App\Models\Conversation;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConversationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->poll('60s')
            ->recordUrl(fn (Conversation $record): string => route(
                'filament.admin.resources.conversations.view',
                ['record' => $record],
            ))
            ->columns([
                IconColumn::make('needs_review')
                    ->label('')
                    ->alignCenter()
                    ->boolean()
                    ->trueIcon('heroicon-s-flag')
                    ->falseIcon('')
                    ->trueColor('warning')
                    ->tooltip(fn (Conversation $record): ?string => $record->review_reason),

                TextColumn::make('started_at')
                    ->label('When')
                    ->dateTime('j M, H:i')
                    ->description(fn (Conversation $record): string => $record->durationForHumans())
                    ->sortable(),

                TextColumn::make('caller_number')
                    ->label('Caller')
                    ->searchable()
                    ->placeholder('Withheld'),

                TextColumn::make('outcome')
                    ->badge()
                    ->sortable(),

                TextColumn::make('order.order_number')
                    ->label('Order')
                    ->placeholder('—')
                    ->url(fn (Conversation $record): ?string => $record->order === null
                        ? null
                        : route('filament.admin.resources.orders.view', ['record' => $record->order])),

                // The first line the caller said. It is the fastest way to
                // scan a list of calls for the one you half-remember.
                TextColumn::make('opening_line')
                    ->label('Opened with')
                    ->limit(60)
                    ->toggleable()
                    ->state(function (Conversation $record): ?string {
                        foreach ($record->transcriptTurns() as $turn) {
                            if ($turn['role'] === 'user') {
                                return $turn['message'];
                            }
                        }

                        return null;
                    }),
            ])
            ->filters([
                Filter::make('needs_review')
                    ->label('Flagged for review')
                    ->query(fn (Builder $query): Builder => $query->where('needs_review', true))
                    // On by default. The whole point of a flag is that somebody
                    // sees it; a list that opens on all four hundred calls of
                    // the week buries it on page nine.
                    ->default(),

                Filter::make('without_order')
                    ->label('No order taken')
                    ->query(fn (Builder $query): Builder => $query->whereDoesntHave('order')),

                SelectFilter::make('outcome')
                    ->options(ConversationOutcome::class)
                    ->multiple(),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('markReviewed')
                    ->label('Reviewed')
                    ->icon('heroicon-m-check')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Clear the flag?')
                    ->modalDescription('The call stays; only the flag goes.')
                    ->visible(fn (Conversation $record): bool => $record->needs_review)
                    ->action(fn (Conversation $record) => $record->markReviewed()),
            ])
            ->toolbarActions([])
            ->emptyStateHeading('No calls yet')
            ->emptyStateDescription('Calls appear here as soon as the agent hangs up and the post-call webhook lands.');
    }
}
