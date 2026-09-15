<?php

declare(strict_types=1);

namespace App\Services\Evals;

use App\Models\Conversation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Restaurant;

/**
 * Grades what the restaurant was left with after a call.
 *
 * Shared by both runners on purpose. Fake mode replays a written-down list of
 * tool calls and live mode lets a real agent decide what to call, but the
 * question afterwards is identical — is there an order, is it for the right
 * food, at the right price, in the right state — and asking it in one place is
 * what makes a live failure and a fake failure comparable.
 *
 * It reads the database rather than the responses. A response saying the total
 * is £18.40 and a row saying 1840 are two different claims, and the one the
 * kitchen prints is the row.
 */
final class OutcomeGrader
{
    /**
     * @param  list<string>  $toolsCalled
     * @return list<Check>
     */
    public function grade(
        Restaurant $restaurant,
        string $conversationId,
        Expectations $expect,
        array $toolsCalled,
    ): array {
        $checks = [];

        foreach ($expect->toolsCalled as $tool) {
            $checks[] = in_array($tool, $toolsCalled, strict: true)
                ? Check::pass(sprintf('called %s', $tool))
                : Check::fail(
                    sprintf('called %s', $tool),
                    sprintf('it never did. Called: %s.', $toolsCalled === [] ? 'nothing' : implode(', ', $toolsCalled)),
                );
        }

        foreach ($expect->toolsNotCalled as $tool) {
            $checks[] = in_array($tool, $toolsCalled, strict: true)
                ? Check::fail(sprintf('did not call %s', $tool), 'but it did.')
                : Check::pass(sprintf('did not call %s', $tool));
        }

        $order = $this->order($restaurant, $conversationId);

        if ($expect->noOrder === true) {
            $checks[] = $order === null
                ? Check::pass('left no order behind')
                : Check::fail('left no order behind', sprintf(
                    'order %s exists, status %s.',
                    $order->order_number,
                    $order->status->value,
                ));
        }

        if ($expect->order !== null) {
            $checks = array_merge($checks, $this->gradeOrder($expect->order, $order));
        }

        if ($expect->escalated !== null || $expect->flaggedForReview !== null) {
            $checks = array_merge($checks, $this->gradeConversation($restaurant, $conversationId, $expect));
        }

        return $checks;
    }

    // -----------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $expected
     * @return list<Check>
     */
    private function gradeOrder(array $expected, ?Order $order): array
    {
        if ($order === null) {
            return [Check::fail('an order was created', 'nothing was created for this conversation.')];
        }

        $checks = [Check::pass('an order was created')];

        foreach ($expected as $field => $value) {
            // `items` is a list of lines rather than one value, so it produces
            // several checks and cannot sit in the match below.
            if ($field === 'items') {
                $checks = array_merge($checks, $this->gradeItems(is_array($value) ? $value : [], $order));

                continue;
            }

            $checks[] = match ($field) {
                'status' => Check::equals('order status', $value, $order->status->value),
                'fulfilment' => Check::equals('fulfilment', $value, $order->fulfilment_type->value),
                'payment_method' => Check::equals('payment method', $value, $order->payment_method?->value),
                'payment_status' => Check::equals('payment status', $value, $order->payment_status->value),
                'total' => $this->money('total', $value, $order->total),
                'subtotal' => $this->money('subtotal', $value, $order->subtotal),
                'delivery_fee' => $this->money('delivery fee', $value, $order->delivery_fee),
                'confirmed' => Check::equals('confirmed', (bool) $value, $order->confirmed_at !== null),
                default => Check::fail(
                    sprintf('order.%s', $field),
                    'is not something a scenario can expect. Try: status, fulfilment, payment_method, '
                    .'payment_status, total, subtotal, delivery_fee, confirmed, items.',
                ),
            };
        }

        return $checks;
    }

    /**
     * @param  array<int|string, mixed>  $expected
     * @return list<Check>
     */
    private function gradeItems(array $expected, Order $order): array
    {
        $items = $order->items()->with('modifiers')->orderBy('sort_order')->get();

        $checks = [Check::equals('number of lines on the order', count($expected), $items->count())];

        foreach (array_values($expected) as $index => $want) {
            if (! is_array($want)) {
                continue;
            }

            $item = $items->get($index);
            $label = sprintf('line %d', $index + 1);

            if ($item === null) {
                $checks[] = Check::fail($label, 'there is no such line on the order.');

                continue;
            }

            $checks[] = $this->gradeItemIdentity($label, $want, $item);

            if (array_key_exists('quantity', $want)) {
                $checks[] = Check::equals($label.' quantity', (int) $want['quantity'], $item->quantity);
            }

            if (array_key_exists('line_total', $want)) {
                $checks[] = $this->money($label.' line total', $want['line_total'], $item->line_total);
            }

            if (array_key_exists('modifiers', $want)) {
                $checks[] = $this->gradeModifiers($label, $want['modifiers'], $item);
            }

            if (array_key_exists('notes', $want)) {
                $checks[] = Check::equals($label.' notes', $want['notes'], $item->notes);
            }
        }

        return $checks;
    }

    /**
     * The dish, by slug if the scenario gave one and by name if it did not.
     *
     * A slug survives the owner retitling a dish and a name does not, which is
     * why the importer and the transcript both carry slugs. Names are still
     * allowed, because a scenario written from a real call has the words the
     * caller used and the dish they got, and that is a perfectly good test.
     *
     * @param  array<int|string, mixed>  $want
     */
    private function gradeItemIdentity(string $label, array $want, OrderItem $item): Check
    {
        $slug = $want['slug'] ?? null;

        if (is_string($slug)) {
            return Check::equals($label.' dish', $slug, $item->menuItem?->slug);
        }

        $name = $want['name'] ?? null;

        if (is_string($name)) {
            return strcasecmp($name, $item->name) === 0
                ? Check::pass($label.' dish')
                : Check::fail($label.' dish', sprintf('expected "%s", got "%s"', $name, $item->name));
        }

        return Check::fail($label, 'says nothing about which dish it is. Give it a "slug" or a "name".');
    }

    /**
     * Modifiers as a set, not a sequence.
     *
     * "Large, no onions" and "no onions, large" are the same order, and a
     * scenario that fails because the agent named the size second would be
     * measuring the wrong thing entirely.
     */
    private function gradeModifiers(string $label, mixed $want, OrderItem $item): Check
    {
        $expected = array_map(strval(...), is_array($want) ? array_values($want) : []);
        $actual = $item->modifiers->map(static fn ($modifier): string => $modifier->name)->values()->all();

        $normalise = static function (array $names): array {
            $lowered = array_map(static fn (string $name): string => mb_strtolower($name), $names);
            sort($lowered);

            return $lowered;
        };

        if ($normalise($expected) === $normalise($actual)) {
            return Check::pass($label.' modifiers');
        }

        return Check::fail($label.' modifiers', sprintf(
            'expected [%s], got [%s]',
            implode(', ', $expected),
            implode(', ', $actual),
        ));
    }

    /**
     * @return list<Check>
     */
    private function gradeConversation(Restaurant $restaurant, string $conversationId, Expectations $expect): array
    {
        $conversation = Conversation::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('elevenlabs_conversation_id', $conversationId)
            ->first();

        $checks = [];

        if ($expect->escalated !== null) {
            $escalated = $conversation?->outcome->value === 'escalated';

            $checks[] = Check::equals('escalated to a human', $expect->escalated, $escalated);
        }

        if ($expect->flaggedForReview !== null) {
            $checks[] = Check::equals('flagged for review', $expect->flaggedForReview, (bool) $conversation?->needs_review);
        }

        return $checks;
    }

    /**
     * The order this conversation produced, if it produced one.
     *
     * At most one, and not by convention: `orders` is unique on
     * (restaurant_id, elevenlabs_conversation_id), which is how create_order is
     * idempotent when ElevenLabs retries a tool call. `latest` is there so that
     * a future relaxation of that index degrades into "grade the one it
     * finished with" rather than into "grade whichever row came back first".
     */
    private function order(Restaurant $restaurant, string $conversationId): ?Order
    {
        return Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('elevenlabs_conversation_id', $conversationId)
            ->latest('id')
            ->first();
    }

    /**
     * Scenarios write money the way a person says it; the database keeps pence.
     */
    private function money(string $label, mixed $expected, int $actual): Check
    {
        if (! is_numeric($expected)) {
            return Check::fail($label, sprintf('%s is not a price.', Check::render($expected)));
        }

        $minor = (int) round(((float) $expected) * 100);

        return $minor === $actual
            ? Check::pass($label)
            : Check::fail($label, sprintf(
                'expected %s, got %s',
                number_format($minor / 100, 2),
                number_format($actual / 100, 2),
            ));
    }
}
