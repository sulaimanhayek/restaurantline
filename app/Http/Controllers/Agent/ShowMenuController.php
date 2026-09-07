<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agent;

use App\Enums\AgentErrorCode;
use App\Http\Requests\Agent\MenuRequest;
use App\Http\Responses\AgentResponse;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/agent/menu
 *
 * The whole menu, or one category of it.
 *
 * Unavailable items stay in the response with a reason attached rather than
 * being filtered out. "We've sold out of the wings" is a much better answer
 * than the agent behaving as though wings were never on the menu, and a caller
 * who asks for something that is lunch-only at 8pm should be told when to call
 * back rather than told it does not exist.
 */
final class ShowMenuController
{
    public function __invoke(MenuRequest $request): JsonResponse
    {
        $restaurant = $request->restaurant();
        $at = $restaurant->now();
        $category = $request->validated()['category'] ?? null;

        $query = $restaurant->menuCategories()
            ->with(['menuItems' => static fn ($items) => $items->orderBy('sort_order')])
            ->orderBy('sort_order');

        if (is_string($category) && $category !== '') {
            $query->where('slug', $category);
        }

        $categories = $query->get();

        if (is_string($category) && $category !== '' && $categories->isEmpty()) {
            return AgentResponse::fail(
                AgentErrorCode::ItemNotFound,
                "I don't have a section called that. Would you like me to run through what we do?",
                ['category' => $category],
            );
        }

        return AgentResponse::ok([
            'restaurant' => $restaurant->name,
            'currency' => $restaurant->currency,
            'categories' => $categories
                ->map(fn (MenuCategory $category): array => [
                    'category' => $category->slug,
                    'name' => $category->name,
                    'description' => $category->description,
                    'available_now' => $category->isAvailableAt($at),
                    'available_when' => $category->spokenAvailability(),
                    'items' => $category->menuItems
                        ->map(fn (MenuItem $item): array => [
                            'item' => $item->slug,
                            'name' => $item->name,
                            'description' => $item->description,
                            'price' => $item->price,
                            'price_spoken' => $item->money()->spoken(),
                            'available' => $item->unavailableReason($at) === null,
                            'unavailable_reason' => $item->unavailableReason($at),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            'modifier_groups' => $this->modifierGroups($request),
        ]);
    }

    /**
     * Every group the restaurant defines, with its modifiers.
     *
     * Sent alongside the menu rather than as a tenth tool. The agent needs to
     * know that "no onions" is a thing this kitchen understands before a caller
     * says it, and a round trip mid-sentence to find out is a pause the caller
     * hears.
     *
     * @return list<array<string, mixed>>
     */
    private function modifierGroups(MenuRequest $request): array
    {
        return $request->restaurant()
            ->modifierGroups()
            ->with(['modifiers' => static fn ($modifiers) => $modifiers->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->map(static fn (ModifierGroup $group): array => [
                'group' => $group->slug,
                'name' => $group->name,
                'selection' => $group->selection_type->value,
                'required' => $group->is_required,
                'min' => $group->min_selections,
                'max' => $group->max_selections,
                'modifiers' => $group->modifiers
                    ->map(static fn (Modifier $modifier): array => [
                        'modifier' => $modifier->slug,
                        'name' => $modifier->name,
                        'kind' => $modifier->kind->value,
                        'price_delta' => $modifier->price_delta,
                        'price_delta_spoken' => $modifier->isFree() ? null : $modifier->money()->spoken(),
                        'available' => $modifier->is_available,
                        'default' => $modifier->is_default,
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
