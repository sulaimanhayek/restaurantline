<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Concerns\ScopesToCurrentRestaurant;
use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Pages\ViewOrder;
use App\Filament\Resources\Orders\Schemas\OrderForm;
use App\Filament\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Orders — the reason anyone opens this dashboard.
 *
 * Sits at the top of the navigation with no group, because it is the only
 * thing most users ever look at. Everything else is configuration they touch
 * once and then forget.
 *
 * The `@extends` tag is fully qualified because Pint's phpdoc_types fixer
 * lowercases a bare `Resource` — PHP has a native `resource` type and the
 * fixer cannot tell the two apart. The leading backslash is what stops it.
 *
 * @extends \Filament\Resources\Resource<Order>
 */
class OrderResource extends Resource
{
    /** @use ScopesToCurrentRestaurant<Order> */
    use ScopesToCurrentRestaurant;

    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'order_number';

    /**
     * Orders are not created here.
     *
     * They arrive from a phone call, through `POST /api/agent/orders`, which
     * enforces things this form could not — a confirmed address, a kitchen
     * that is actually open, no card numbers in the notes. A dashboard
     * "create order" button would be a second, weaker path to the same table.
     *
     * `OrderSource::Dashboard` exists for the day this repo grows a proper
     * counter-service flow. That is not this phase.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Neither are they deleted.
     *
     * An order is a financial record with a phone call behind it. The thing an
     * operator actually wants when they reach for delete is
     * `OrderStatus::Cancelled`, which the edit form offers and which keeps the
     * row, the transcript link and the reason. Deleting instead would lose the
     * one artefact that answers "what did the customer actually say?".
     *
     * Overridden rather than left to a policy so that a bulk delete action
     * added to the table later is refused too.
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
     * Live orders, as a badge on the nav item.
     *
     * This is the number somebody glances at from across the room, so it counts
     * what is actually in the kitchen right now rather than everything taken
     * today.
     */
    public static function getNavigationBadge(): ?string
    {
        $live = static::getEloquentQuery()
            ->whereIn('status', OrderStatus::liveOnKitchenDisplay())
            ->count();

        return $live > 0 ? (string) $live : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    protected static function modifyScopedQuery(Builder $query): Builder
    {
        // The table shows the caller and an item count on every row.
        return $query->with(['customer', 'conversation'])->withCount('items');
    }

    public static function form(Schema $schema): Schema
    {
        return OrderForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
            'edit' => EditOrder::route('/{record}/edit'),
        ];
    }
}
