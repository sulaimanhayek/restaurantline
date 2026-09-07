<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\ModifierKind;
use App\Models\DeliveryFeeRule;
use App\Models\Restaurant;
use InvalidArgumentException;

/**
 * The one place a total is worked out.
 *
 * Every price is an integer in the currency's minor unit from end to end, so
 * there is no rounding step and no float to drift. The service reads prices
 * from the live menu and never from an order — pricing a cart and pricing a
 * saved order are different questions, and conflating them is how a price rise
 * ends up rewriting last week's receipts.
 *
 * It writes nothing. Availability, opening hours and delivery radius are all
 * checked elsewhere: this class answers "what does this cost", not "may they
 * have it".
 *
 * @see docs/DECISIONS.md #0001, #0011
 */
final class PricingService
{
    public function price(Cart $cart): PricedOrder
    {
        $currency = $cart->restaurant->currency;

        $lines = [];
        $sortOrder = 0;

        foreach ($cart->lines as $line) {
            $lines[] = $this->priceLine($line, $currency, $sortOrder++);
        }

        $subtotal = array_sum(array_map(
            static fn (PricedLine $line): int => $line->lineTotal,
            $lines,
        ));

        [$deliveryFee, $waived] = $cart->isDelivery()
            ? $this->deliveryFee($cart->restaurant, $cart->distanceMetres, $subtotal)
            : [0, false];

        $minimum = $this->minimumFor($cart);
        $shortfall = max(0, $minimum - $subtotal);

        return new PricedOrder(
            lines: $lines,
            fulfilment: $cart->fulfilment,
            subtotal: $subtotal,
            deliveryFee: $deliveryFee,
            total: $subtotal + $deliveryFee,
            meetsMinimum: $shortfall === 0,
            minimumOrderValue: $minimum,
            shortfall: $shortfall,
            distanceMetres: $cart->distanceMetres,
            deliveryFeeWaived: $waived,
            currency: $currency,
        );
    }

    /**
     * Price one line, resolving every modifier against the item it is on.
     *
     * A modifier's delta comes from the item's per-item override where one
     * exists and from the modifier row otherwise — resolvedPriceDelta() owns
     * that rule, and this method must not second-guess it.
     */
    public function priceLine(CartLine $line, string $currency, int $sortOrder = 0): PricedLine
    {
        if ($line->quantity < 1) {
            // Not clamped on purpose. A zero quantity reaching here means
            // something upstream lost the caller's words, and quietly pricing
            // it as one is worse than failing loudly.
            throw new InvalidArgumentException(
                sprintf('Quantity for "%s" must be at least 1, got %d.', $line->item->name, $line->quantity),
            );
        }

        $item = $line->item;
        $item->loadMissing('modifierOverrides');

        $modifiers = [];

        foreach ($line->modifiers as $chosen) {
            $modifier = $chosen->modifier;
            $modifier->loadMissing('group');

            $modifiers[] = new PricedModifier(
                modifierId: $modifier->id,
                modifierGroupId: $modifier->modifier_group_id,
                groupName: $modifier->group->name,
                name: $modifier->name,
                kind: $modifier->kind,
                priceDelta: $item->resolvedPriceDelta($modifier),
                // You cannot leave the onions out twice. Normalising here keeps
                // "3× no onions" off the ticket and out of the read-back.
                quantity: $modifier->kind === ModifierKind::Removal ? 1 : max(1, $chosen->quantity),
                currency: $currency,
            );
        }

        $modifiersTotal = array_sum(array_map(
            static fn (PricedModifier $modifier): int => $modifier->total(),
            $modifiers,
        ));

        return new PricedLine(
            menuItemId: $item->id,
            name: $item->name,
            description: $item->description,
            sku: $item->sku,
            unitPrice: $item->price,
            modifiersTotal: $modifiersTotal,
            quantity: $line->quantity,
            lineTotal: ($item->price + $modifiersTotal) * $line->quantity,
            modifiers: $modifiers,
            notes: $line->notes,
            currency: $currency,
            sortOrder: $sortOrder,
        );
    }

    /**
     * The delivery fee for a distance and subtotal.
     *
     * Bands are evaluated in `sort_order`; the first whose `up_to_metres`
     * covers the distance wins, and a band with a null `up_to_metres` is the
     * catch-all.
     *
     * @return array{0: int, 1: bool} The fee, and whether a free-delivery
     *                                threshold waived it.
     */
    public function deliveryFee(Restaurant $restaurant, ?int $distanceMetres, int $subtotal): array
    {
        $rules = $restaurant->deliveryFeeRules;

        if ($rules->isEmpty()) {
            return [$restaurant->base_delivery_fee, false];
        }

        // No distance yet — the caller has given an address the agent has not
        // resolved, or is quoting before choosing one. The flat fee is the
        // honest answer; the quote is re-run once the address is confirmed.
        if ($distanceMetres === null) {
            return [$restaurant->base_delivery_fee, false];
        }

        $rule = $rules->first(
            static fn (DeliveryFeeRule $rule): bool => $rule->covers($distanceMetres),
        );

        // Beyond every band and no catch-all configured. Charging the furthest
        // band is the least wrong answer available here; whether the address is
        // deliverable at all is the delivery radius check's job, not this one's.
        $rule ??= $rules->last();

        if ($rule->free_over_subtotal !== null && $subtotal >= $rule->free_over_subtotal) {
            return [0, true];
        }

        return [$rule->fee, false];
    }

    /**
     * The minimum order value in force for this cart.
     *
     * Collection orders are exempt by default: almost no takeaway turns away a
     * customer standing at the counter for spending too little.
     */
    private function minimumFor(Cart $cart): int
    {
        if (! $cart->isDelivery() && ! (bool) config('restaurantline.pricing.minimum_applies_to_collection')) {
            return 0;
        }

        return $cart->restaurant->minimum_order_value;
    }
}
