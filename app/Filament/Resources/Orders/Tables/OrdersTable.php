<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Restaurant;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The order list.
 *
 * Sorted newest first and polled, because this is the screen someone leaves
 * open on a laptop behind the counter. The default Filament table sorts by id
 * ascending, which would put tonight's orders below every order the restaurant
 * has ever taken.
 *
 * Deliberately not showing: the internal id, the restaurant column (there is
 * one), and timestamps nobody reads. Every column here answers a question
 * somebody actually asks out loud during service.
 */
class OrdersTable
{
    public static function configure(Table $table): Table
    {
        $currency = Restaurant::current()->currency;

        return $table
            ->defaultSort('created_at', 'desc')
            // Orders arrive by phone while this screen is open. Thirty seconds
            // is frequent enough that nobody refreshes manually, and slow
            // enough that it is not a load problem. The kitchen display in the
            // next phase gets real broadcasts instead.
            ->poll('30s')
            ->columns([
                TextColumn::make('order_number')
                    ->label('Order')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn ($record): ?string => $record->source->getLabel()),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('fulfilment_type')
                    ->label('Type')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('customer.phone_number')
                    ->label('Caller')
                    ->searchable()
                    ->placeholder('—')
                    ->description(fn ($record): ?string => $record->customer?->name),

                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->alignCenter(),

                TextColumn::make('total')
                    ->money($currency, divideBy: 100)
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('payment_status')
                    ->label('Payment')
                    ->badge(),

                TextColumn::make('created_at')
                    ->label('Taken')
                    ->since()
                    ->sortable()
                    ->tooltip(fn ($record): ?string => $record->created_at?->toDayDateTimeString()),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(OrderStatus::class)
                    ->multiple(),

                SelectFilter::make('fulfilment_type')
                    ->label('Type')
                    ->options(FulfilmentType::class),

                SelectFilter::make('payment_status')
                    ->label('Payment')
                    ->options(PaymentStatus::class)
                    ->multiple(),

                /*
                 * The one filter worth having by default.
                 *
                 * An order stuck in `confirming` is a caller who hung up
                 * mid-order, and an unpaid delivery that is already out is
                 * money walking away. Both are invisible on a list sorted by
                 * time, because they are old rows — precisely the ones that
                 * scroll off the first page.
                 */
                TernaryFilter::make('needs_attention')
                    ->label('Needs attention')
                    ->placeholder('All orders')
                    ->trueLabel('Unconfirmed or unpaid')
                    ->falseLabel('Everything else')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(
                            fn (Builder $q): Builder => $q
                                ->where('status', OrderStatus::Confirming)
                                ->orWhere(
                                    fn (Builder $inner): Builder => $inner
                                        ->whereIn('status', [OrderStatus::Completed, OrderStatus::Ready])
                                        ->whereIn('payment_status', [PaymentStatus::Unpaid, PaymentStatus::LinkSent]),
                                ),
                        ),
                        false: fn (Builder $query): Builder => $query->whereNot(
                            fn (Builder $q): Builder => $q->where('status', OrderStatus::Confirming),
                        ),
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            // No delete. An order is a financial record and a caller's word;
            // cancelling one is a status change with a reason attached, not a
            // row disappearing. Bulk-deleting them is not a thing a dashboard
            // should make easy.
            ->toolbarActions([
                BulkActionGroup::make([]),
            ])
            ->emptyStateHeading('No orders yet')
            ->emptyStateDescription('Orders taken by the voice agent appear here as soon as the caller confirms them.');
    }
}
