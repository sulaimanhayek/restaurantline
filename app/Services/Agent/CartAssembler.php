<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\AgentErrorCode;
use App\Enums\FulfilmentType;
use App\Enums\ModifierKind;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\Restaurant;
use App\Services\Menu\MenuMatch;
use App\Services\Menu\MenuMatchingService;
use App\Services\Pricing\Cart;
use App\Services\Pricing\CartLine;
use App\Services\Pricing\CartModifier;
use Carbon\CarbonInterface;

/**
 * The wire format, turned into something the pricing service can price.
 *
 * `/quote` and `/orders` take the same `items` array and both have to resolve
 * it identically — a quote the caller agreed to and an order that prices
 * differently is the worst bug this system could have. So resolution lives
 * here, once, and both endpoints call it.
 *
 * Items and modifiers arrive as slugs (#0018). A slug that does not resolve is
 * treated as a near miss rather than a dead end: the agent is asked to search
 * the menu properly, and gets the closest matches to offer. A model that says
 * `chicken-burger` when the menu says `ember-chicken-burger` is one prompt away
 * from being right, and answering "no such item" with no alternatives wastes
 * the caller's time on something the server can see the answer to.
 */
final class CartAssembler
{
    public function __construct(private MenuMatchingService $menu) {}

    /**
     * @param  list<array{item: string, quantity?: int, modifiers?: list<array{modifier: string, quantity?: int}>, notes?: string|null}>  $items
     *
     * @throws CartAssemblyException
     */
    public function assemble(
        Restaurant $restaurant,
        FulfilmentType $fulfilment,
        array $items,
        ?int $distanceMetres = null,
        ?CarbonInterface $at = null,
    ): Cart {
        $at ??= $restaurant->now();

        if ($items === []) {
            throw new CartAssemblyException(
                AgentErrorCode::EmptyOrder,
                "I haven't got anything on the order yet. What would you like?",
            );
        }

        $lines = [];

        foreach ($items as $item) {
            $lines[] = $this->line($restaurant, $item, $at);
        }

        return new Cart(
            restaurant: $restaurant,
            fulfilment: $fulfilment,
            lines: $lines,
            distanceMetres: $distanceMetres,
        );
    }

    /**
     * @param  array{item: string, quantity?: int, modifiers?: list<array{modifier: string, quantity?: int}>, notes?: string|null}  $line
     *
     * @throws CartAssemblyException
     */
    private function line(Restaurant $restaurant, array $line, CarbonInterface $at): CartLine
    {
        $item = $this->resolveItem($restaurant, $line['item'], $at);

        $reason = $item->unavailableReason($at);

        if ($reason !== null) {
            throw new CartAssemblyException(
                AgentErrorCode::ItemUnavailable,
                sprintf("I'm sorry, the %s is %s.", $item->name, $reason),
                ['item' => $item->slug, 'name' => $item->name, 'reason' => $reason],
            );
        }

        $modifiers = [];

        foreach ($line['modifiers'] ?? [] as $chosen) {
            $modifiers[] = $this->resolveModifier($item, $chosen);
        }

        return new CartLine(
            item: $item,
            // Validation floors this at 1, so the pricing service's own guard
            // should never fire from this path. It stays there for the other
            // callers pricing carts that did not come off the wire.
            quantity: max(1, (int) ($line['quantity'] ?? 1)),
            modifiers: $modifiers,
            notes: $this->notes($line['notes'] ?? null),
        );
    }

    /**
     * @throws CartAssemblyException
     */
    private function resolveItem(Restaurant $restaurant, string $slug, CarbonInterface $at): MenuItem
    {
        $item = $restaurant->menuItems()
            ->with(['category', 'modifierGroups.modifiers', 'modifierOverrides'])
            ->where('slug', $slug)
            ->first();

        if ($item !== null) {
            return $item;
        }

        // A slug is a hyphenated phrase. Feeding it back through the matcher
        // costs one query and usually finds exactly what the model meant.
        $suggestions = $this->menu
            ->search($restaurant, str_replace('-', ' ', $slug), $at)
            ->alternatives();

        throw new CartAssemblyException(
            AgentErrorCode::ItemNotFound,
            $suggestions === []
                ? "I can't find that on the menu."
                : sprintf(
                    "I can't find that on the menu. Did you mean %s?",
                    $this->orList(array_map(static fn (MenuMatch $m): string => $m->item->name, $suggestions)),
                ),
            [
                'item' => $slug,
                'suggestions' => array_map(static fn (MenuMatch $m): array => $m->toAgentArray(), $suggestions),
            ],
        );
    }

    /**
     * @param  array{modifier: string, quantity?: int}  $chosen
     *
     * @throws CartAssemblyException
     */
    private function resolveModifier(MenuItem $item, array $chosen): CartModifier
    {
        $slug = $chosen['modifier'];

        /** @var Modifier|null $modifier */
        $modifier = $item->modifierGroups
            ->flatMap(static fn ($group) => $group->modifiers)
            ->firstWhere('slug', $slug);

        if ($modifier === null) {
            // Deliberately one code for both "no such modifier anywhere" and
            // "not on this item". From the caller's side they are the same
            // sentence, and the agent's next move is the same either way.
            throw new CartAssemblyException(
                AgentErrorCode::ModifierNotFound,
                sprintf("I can't do that with the %s.", $item->name),
                ['item' => $item->slug, 'modifier' => $slug],
            );
        }

        if (! $item->resolvedModifierAvailability($modifier)) {
            throw new CartAssemblyException(
                AgentErrorCode::ModifierUnavailable,
                sprintf("I'm sorry, we're out of %s.", mb_strtolower($modifier->name)),
                ['item' => $item->slug, 'modifier' => $modifier->slug, 'name' => $modifier->name],
            );
        }

        return new CartModifier(
            modifier: $modifier,
            // You cannot leave the onions out three times. The pricing service
            // normalises this too; doing it here as well keeps the quantity the
            // agent sees echoed back equal to the one it will be charged for.
            quantity: $modifier->kind === ModifierKind::Removal
                ? 1
                : max(1, (int) ($chosen['quantity'] ?? 1)),
        );
    }

    private function notes(?string $notes): ?string
    {
        $notes = trim((string) $notes);

        return $notes === '' ? null : $notes;
    }

    /**
     * @param  list<string>  $names
     */
    private function orList(array $names): string
    {
        if (count($names) === 1) {
            return $names[0];
        }

        $last = array_pop($names);

        return implode(', ', $names).' or '.$last;
    }
}
