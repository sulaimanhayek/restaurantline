<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmsMessages;

use App\Enums\SmsStatus;
use App\Filament\Concerns\ScopesToCurrentRestaurant;
use App\Filament\Resources\SmsMessages\Pages\ListSmsMessages;
use App\Filament\Resources\SmsMessages\Pages\ViewSmsMessage;
use App\Filament\Resources\SmsMessages\Schemas\SmsMessageInfolist;
use App\Filament\Resources\SmsMessages\Tables\SmsMessagesTable;
use App\Models\SmsMessage;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Texts.
 *
 * Opened for one question, almost always: "the customer says they never got
 * their payment link." The answer is on this screen in about fifteen seconds —
 * whether a text went out, to which number, and what the provider said if it
 * did not — which is the whole reason the send and the record are written by
 * the same service.
 *
 * Read-only, and next to Calls rather than under Settings, because both are
 * records of something that already happened rather than things to configure.
 * Resending lives on the order, where somebody looking at an unpaid delivery
 * already is.
 *
 * The `@extends` tag is fully qualified because Pint's phpdoc_types fixer
 * lowercases a bare `Resource` — PHP has a native `resource` type and the
 * fixer cannot tell the two apart. The leading backslash is what stops it.
 *
 * @extends \Filament\Resources\Resource<SmsMessage>
 */
class SmsMessageResource extends Resource
{
    /** @use ScopesToCurrentRestaurant<SmsMessage> */
    use ScopesToCurrentRestaurant;

    protected static ?string $model = SmsMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'text';

    protected static ?string $pluralModelLabel = 'texts';

    protected static ?string $recordTitleAttribute = 'to_number';

    /**
     * Nothing is composed here.
     *
     * Every text this application sends is a consequence of something — an
     * order confirmed, a link resent from that order's page — and a free-text
     * box pointed at a customer's phone is a support incident waiting to be
     * typed. The resend action on an order is the supported way to send one by
     * hand, and it sends a message this application composed.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * And nothing is deleted.
     *
     * This table is the answer to a billing question and a customer dispute,
     * both of which are asked after the fact. A row that can be removed is a
     * row that stops being evidence.
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
     * Texts that did not go out.
     *
     * Red, and counted over the last day rather than for all time: a failure
     * from last month is history, and a failure from this evening is a customer
     * waiting for a link that is never coming.
     */
    public static function getNavigationBadge(): ?string
    {
        $failed = static::getEloquentQuery()
            ->where('status', SmsStatus::Failed)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        return $failed > 0 ? (string) $failed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * @param  Builder<SmsMessage>  $query
     * @return Builder<SmsMessage>
     */
    protected static function modifyScopedQuery(Builder $query): Builder
    {
        return $query->with(['order', 'customer']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmsMessageInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmsMessagesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmsMessages::route('/'),
            'view' => ViewSmsMessage::route('/{record}'),
        ];
    }
}
