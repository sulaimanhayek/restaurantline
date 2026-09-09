<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conversations\Schemas;

use App\Models\Conversation;
use App\Models\Order;
use App\Models\Restaurant;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;

/**
 * The review screen — the one page in this dashboard that is not CRUD.
 *
 * Someone opens this because a call went wrong, or because they are tuning the
 * agent and want to know why it said what it said. That job is reading the
 * transcript against the order it produced, so the two sit side by side and
 * the audio plays from whichever line you click.
 *
 * The order pane deliberately shows what the *order* says rather than what the
 * transcript says, and does not try to reconcile them. Spotting the difference
 * is the entire point of the screen, and a summary that smoothed it over would
 * be actively harmful.
 *
 * @see docs/DECISIONS.md #0027
 */
class ConversationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $currency = Restaurant::current()->currency;

        return $schema->components([
            // The flag, if there is one, before anything else. A reviewer
            // should not have to work out why the call is in front of them.
            Callout::make('review')
                ->color('warning')
                ->icon('heroicon-o-exclamation-triangle')
                ->heading('Flagged for review')
                ->description(fn (Conversation $record): string => $record->review_reason
                    ?? 'No reason recorded.')
                ->visible(fn (Conversation $record): bool => $record->needs_review),

            Callout::make('no_order')
                ->color('gray')
                ->icon('heroicon-o-phone-x-mark')
                ->heading('This call did not produce an order')
                ->description(fn (Conversation $record): string => sprintf(
                    'Outcome recorded as "%s". If that is wrong, the transcript below is where to find out why.',
                    $record->outcome->label(),
                ))
                ->visible(fn (Conversation $record): bool => ! $record->producedAnOrder()),

            Grid::make(5)->schema([
                TextEntry::make('caller_number')
                    ->label('Caller')
                    ->copyable()
                    ->placeholder('Withheld'),

                TextEntry::make('outcome')
                    ->badge(),

                TextEntry::make('started_at')
                    ->label('Started')
                    ->dateTime('j M Y, H:i')
                    ->placeholder('Unknown'),

                TextEntry::make('duration_seconds')
                    ->label('Length')
                    ->state(fn (Conversation $record): string => $record->durationForHumans()),

                TextEntry::make('direction')
                    ->badge()
                    ->color('gray'),
            ]),

            Grid::make(['default' => 1, 'xl' => 3])->schema([
                Section::make('The call')
                    ->columnSpan(['default' => 1, 'xl' => 2])
                    ->schema([
                        ViewEntry::make('transcript')
                            ->hiddenLabel()
                            ->view('filament.conversations.review'),
                    ]),

                Section::make('What it produced')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('order.order_number')
                            ->label('Order')
                            ->weight('bold')
                            ->url(fn (Conversation $record): ?string => $record->order === null
                                ? null
                                : route('filament.admin.resources.orders.view', ['record' => $record->order]))
                            ->placeholder('No order'),

                        TextEntry::make('order.status')
                            ->label('Status')
                            ->badge()
                            ->visible(fn (Conversation $record): bool => $record->order !== null),

                        TextEntry::make('order.fulfilment_type')
                            ->label('Fulfilment')
                            ->badge()
                            ->color('gray')
                            ->visible(fn (Conversation $record): bool => $record->order !== null),

                        TextEntry::make('order_items')
                            ->label('Items')
                            ->listWithLineBreaks()
                            ->state(fn (Conversation $record): array => self::itemLines($record->order))
                            ->visible(fn (Conversation $record): bool => $record->order !== null),

                        TextEntry::make('order.total')
                            ->label('Total')
                            ->size(TextSize::Large)
                            ->weight('bold')
                            ->formatStateUsing(fn (int $state): string => Money::of($state, $currency)->format())
                            ->visible(fn (Conversation $record): bool => $record->order !== null),
                    ]),
            ]),

            Section::make('What ElevenLabs made of it')
                ->description('The provider\'s own summary and evaluation criteria. Useful for tuning the prompt; not a substitute for reading the transcript.')
                ->collapsed()
                ->schema([
                    TextEntry::make('analysis.transcript_summary')
                        ->label('Summary')
                        ->placeholder('None supplied.')
                        ->columnSpanFull(),

                    TextEntry::make('cost_credits')
                        ->label('Credits')
                        ->placeholder('Not reported'),

                    TextEntry::make('elevenlabs_conversation_id')
                        ->label('Conversation ID')
                        ->copyable()
                        ->size(TextSize::Small),

                    TextEntry::make('elevenlabs_agent_id')
                        ->label('Agent ID')
                        ->copyable()
                        ->size(TextSize::Small)
                        ->placeholder('Not reported'),
                ])
                ->columns(3),
        ]);
    }

    /**
     * The order's lines, from the snapshot on each row.
     *
     * @return list<string>
     */
    private static function itemLines(?Order $order): array
    {
        if ($order === null) {
            return [];
        }

        return $order->items
            ->map(fn ($item): string => sprintf('%d × %s', $item->quantity, $item->name))
            ->values()
            ->all();
    }
}
