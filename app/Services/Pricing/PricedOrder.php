<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\FulfilmentType;
use App\Support\Money;

/**
 * A fully priced cart.
 *
 * The quote endpoint returns this; order creation persists it. Nothing
 * downstream recomputes a total — if two numbers could disagree, eventually
 * they will, and the one the customer heard is the one they will hold you to.
 */
final readonly class PricedOrder
{
    /**
     * @param  list<PricedLine>  $lines
     * @param  int  $shortfall  How much more is needed to reach the minimum
     *                          order value; 0 when the minimum is met or does
     *                          not apply.
     */
    public function __construct(
        public array $lines,
        public FulfilmentType $fulfilment,
        public int $subtotal,
        public int $deliveryFee,
        public int $total,
        public bool $meetsMinimum,
        public int $minimumOrderValue,
        public int $shortfall,
        public ?int $distanceMetres,
        public bool $deliveryFeeWaived,
        public string $currency,
    ) {}

    public function subtotalMoney(): Money
    {
        return Money::of($this->subtotal, $this->currency);
    }

    public function deliveryFeeMoney(): Money
    {
        return Money::of($this->deliveryFee, $this->currency);
    }

    public function totalMoney(): Money
    {
        return Money::of($this->total, $this->currency);
    }

    public function shortfallMoney(): Money
    {
        return Money::of($this->shortfall, $this->currency);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function itemCount(): int
    {
        return array_sum(array_map(
            static fn (PricedLine $line): int => $line->quantity,
            $this->lines,
        ));
    }

    /**
     * The order read back to the caller, line by line, ending with the total.
     *
     * This is the read-back the whole design depends on: an order is created in
     * `confirming` and only becomes `confirmed` once the caller has heard this
     * and agreed to it.
     */
    public function spokenReadBack(): string
    {
        $parts = array_map(
            static fn (PricedLine $line): string => $line->spoken(),
            $this->lines,
        );

        if ($this->deliveryFee > 0) {
            $parts[] = sprintf('Delivery, %s', $this->deliveryFeeMoney()->spoken());
        } elseif ($this->fulfilment === FulfilmentType::Delivery && $this->deliveryFeeWaived) {
            $parts[] = 'Free delivery';
        }

        $parts[] = sprintf('That comes to %s', $this->totalMoney()->spoken());

        return implode('. ', $parts).'.';
    }

    /**
     * The JSON an agent tool returns. Short, unambiguous, no nesting the model
     * has to reason about, and every money field paired with a spoken form so
     * the agent never has to read a number out of an integer.
     *
     * @return array<string, mixed>
     */
    public function toAgentArray(): array
    {
        return [
            'items' => array_map(static fn (PricedLine $line): array => [
                'name' => $line->name,
                'quantity' => $line->quantity,
                'modifiers' => array_map(
                    static fn (PricedModifier $modifier): string => $modifier->spoken(),
                    $line->modifiers,
                ),
                'line_total' => $line->lineTotal,
                'line_total_spoken' => $line->lineTotalMoney()->spoken(),
            ], $this->lines),
            'subtotal' => $this->subtotal,
            'subtotal_spoken' => $this->subtotalMoney()->spoken(),
            'delivery_fee' => $this->deliveryFee,
            'delivery_fee_spoken' => $this->deliveryFeeMoney()->spoken(),
            'total' => $this->total,
            'total_spoken' => $this->totalMoney()->spoken(),
            'currency' => $this->currency,
            'meets_minimum' => $this->meetsMinimum,
            'shortfall_spoken' => $this->meetsMinimum ? null : $this->shortfallMoney()->spoken(),
            'read_back' => $this->spokenReadBack(),
        ];
    }
}
