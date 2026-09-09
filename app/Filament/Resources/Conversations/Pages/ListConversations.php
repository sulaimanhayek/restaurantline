<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conversations\Pages;

use App\Filament\Resources\Conversations\ConversationResource;
use Filament\Resources\Pages\ListRecords;

class ListConversations extends ListRecords
{
    protected static string $resource = ConversationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
