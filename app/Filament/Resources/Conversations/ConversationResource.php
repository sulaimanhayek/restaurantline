<?php

declare(strict_types=1);

namespace App\Filament\Resources\Conversations;

use App\Filament\Concerns\ScopesToCurrentRestaurant;
use App\Filament\Resources\Conversations\Pages\ListConversations;
use App\Filament\Resources\Conversations\Pages\ViewConversation;
use App\Filament\Resources\Conversations\Schemas\ConversationInfolist;
use App\Filament\Resources\Conversations\Tables\ConversationsTable;
use App\Models\Conversation;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Calls.
 *
 * Read-only by construction: a conversation is a record of something that
 * happened, and the only mutable thing about it is whether a human has looked
 * at it yet.
 *
 * The `@extends` tag is fully qualified because Pint's phpdoc_types fixer
 * lowercases a bare `Resource` — PHP has a native `resource` type and the
 * fixer cannot tell the two apart. The leading backslash is what stops it.
 *
 * @extends \Filament\Resources\Resource<Conversation>
 */
class ConversationResource extends Resource
{
    /** @use ScopesToCurrentRestaurant<Conversation> */
    use ScopesToCurrentRestaurant;

    protected static ?string $model = Conversation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhone;

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'call';

    protected static ?string $pluralModelLabel = 'calls';

    protected static ?string $recordTitleAttribute = 'elevenlabs_conversation_id';

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * A call is evidence, so nothing here deletes one.
     *
     * The transcript and recording are how a disputed order gets settled, and
     * how the agent's prompt gets fixed. The dashboard offers "mark reviewed",
     * which is what someone reaching for delete actually means.
     *
     * Retention is a separate, deliberate job — see docs/DECISIONS.md #0032 on
     * recordings — not a button next to a row.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Calls waiting on a human.
     *
     * Red rather than the orders badge's amber: an unreviewed flagged call is
     * a thing somebody already decided went wrong.
     */
    public static function getNavigationBadge(): ?string
    {
        $flagged = static::getEloquentQuery()->where('needs_review', true)->count();

        return $flagged > 0 ? (string) $flagged : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    protected static function modifyScopedQuery(Builder $query): Builder
    {
        return $query->with(['order.items']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ConversationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConversationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConversations::route('/'),
            'view' => ViewConversation::route('/{record}'),
        ];
    }
}
