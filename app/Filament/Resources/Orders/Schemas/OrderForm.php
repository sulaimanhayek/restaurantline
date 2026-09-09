<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * The only parts of an order a human should be editing by hand.
 *
 * Not the items, not the prices, not the address. Those were agreed with a
 * caller on a recorded line, and a dashboard that lets someone quietly change
 * what was agreed is a dashboard that loses arguments with customers. Fixing a
 * wrong order means talking to them and taking a new one.
 *
 * What is editable is what a human legitimately knows better than the agent
 * did: where the order has got to, whether the money arrived, and anything the
 * kitchen needs told.
 */
class OrderForm
{
    public static function configure(Schema $schema): Schema
    {
        // A Select backed by an enum class holds the enum case in form state,
        // but the same field holds a plain string the moment it is filled from
        // a request. Both are compared here so the reason field behaves the
        // same whether a person picked "Cancelled" or a test filled it in.
        $isCancelled = fn (Get $get): bool => $get('status') === OrderStatus::Cancelled
            || $get('status') === OrderStatus::Cancelled->value;

        return $schema->components([
            Section::make('Progress')
                ->description('Everything else on an order is what the caller agreed to, and is deliberately read-only.')
                ->schema([
                    Select::make('status')
                        ->options(OrderStatus::class)
                        ->required()
                        ->native(false)
                        // Live because the reason field below appears off the
                        // back of it. Without this, choosing "Cancelled" saves
                        // a cancelled order with no reason and never asks.
                        ->live(),

                    Select::make('payment_status')
                        ->label('Payment')
                        ->options(PaymentStatus::class)
                        ->required()
                        ->native(false),

                    TextInput::make('cancellation_reason')
                        ->label('Reason for cancelling')
                        ->maxLength(255)
                        // Only asked for when it is relevant, and required
                        // then: a cancelled order with no reason is a support
                        // conversation nobody can reconstruct later.
                        ->visible($isCancelled)
                        ->required($isCancelled),

                    Textarea::make('notes')
                        ->label('Notes for the kitchen')
                        ->rows(3)
                        ->maxLength(2000),
                ]),
        ]);
    }
}
