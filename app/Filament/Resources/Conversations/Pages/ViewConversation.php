<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conversations\Pages;

use App\Filament\Resources\Conversations\ConversationResource;
use App\Models\Conversation;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewConversation extends ViewRecord
{
    protected static string $resource = ConversationResource::class;

    public function getTitle(): string
    {
        /** @var Conversation $record */
        $record = $this->getRecord();

        return $record->caller_number ?? 'Withheld number';
    }

    /**
     * The two things a reviewer does at the end of reading a call: decide it is
     * fine, or write down what was wrong with it so the next person knows.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('markReviewed')
                ->label('Mark reviewed')
                ->icon('heroicon-m-check')
                ->color('success')
                ->visible(fn (Conversation $record): bool => $record->needs_review)
                ->action(function (Conversation $record): void {
                    $record->markReviewed();

                    Notification::make()
                        ->title('Flag cleared')
                        ->success()
                        ->send();
                }),

            Action::make('flagForReview')
                ->label('Flag for review')
                ->icon('heroicon-m-flag')
                ->color('warning')
                ->visible(fn (Conversation $record): bool => ! $record->needs_review)
                ->schema([
                    Textarea::make('reason')
                        ->label('What went wrong?')
                        ->required()
                        ->rows(3)
                        ->helperText('Written for whoever picks this up next, including future you.'),
                ])
                ->action(function (Conversation $record, array $data): void {
                    $record->flagForReview((string) $data['reason']);

                    Notification::make()
                        ->title('Flagged')
                        ->success()
                        ->send();
                }),
        ];
    }
}
