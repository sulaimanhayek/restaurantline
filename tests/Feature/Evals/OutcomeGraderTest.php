<?php

declare(strict_types=1);

use App\Enums\ConversationOutcome;
use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Conversation;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderItemModifier;
use App\Models\Restaurant;
use App\Services\Evals\Check;
use App\Services\Evals\Expectations;
use App\Services\Evals\OutcomeGrader;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Grading what the restaurant was left with
|--------------------------------------------------------------------------
|
| Shared by both runners, which is the point: a live failure and a fake failure
| have to read the same or there is no comparing them. It reads the database
| rather than the responses, because a response claiming £18.40 and a row
| holding 1840 are two different claims and the kitchen prints the row.
|
*/

beforeEach(function (): void {
    $this->restaurant = restaurant();
    $this->conversationId = 'conv_eval_test';
    $this->grader = app(OutcomeGrader::class);

    $this->grade = fn (Expectations $expect, array $toolsCalled = []): array => $this->grader->grade(
        $this->restaurant,
        $this->conversationId,
        $expect,
        $toolsCalled,
    );

    $this->labels = fn (array $checks, bool $passed): array => array_values(array_map(
        fn (Check $check): string => $check->label,
        array_filter($checks, fn (Check $check): bool => $check->passed === $passed),
    ));
});

/**
 * @param  array<string, mixed>  $attributes
 */
function gradedOrder(Restaurant $restaurant, string $conversationId, array $attributes = []): Order
{
    return Order::factory()->create([
        'restaurant_id' => $restaurant->id,
        'elevenlabs_conversation_id' => $conversationId,
        ...$attributes,
    ]);
}

// -----------------------------------------------------------------------
// Tools
// -----------------------------------------------------------------------

it('checks which tools were called and which were not', function (): void {
    $checks = ($this->grade)(
        new Expectations(toolsCalled: ['quote_order', 'confirm_order'], toolsNotCalled: ['escalate_to_human']),
        ['quote_order', 'create_order'],
    );

    expect(($this->labels)($checks, true))->toBe(['called quote_order', 'did not call escalate_to_human'])
        ->and(($this->labels)($checks, false))->toBe(['called confirm_order']);
});

it('lists what was actually called when an expected tool was not', function (): void {
    $checks = ($this->grade)(new Expectations(toolsCalled: ['confirm_order']), ['quote_order']);

    expect($checks[0]->detail)->toBe('it never did. Called: quote_order.');
});

it('says nothing was called when nothing was', function (): void {
    $checks = ($this->grade)(new Expectations(toolsCalled: ['confirm_order']), []);

    expect($checks[0]->detail)->toContain('Called: nothing.');
});

// -----------------------------------------------------------------------
// Whether there is an order at all
// -----------------------------------------------------------------------

it('passes no_order when the call left nothing behind', function (): void {
    $checks = ($this->grade)(new Expectations(noOrder: true));

    expect($checks[0]->passed)->toBeTrue()
        ->and($checks[0]->label)->toBe('left no order behind');
});

it('names the order that should not exist', function (): void {
    gradedOrder($this->restaurant, $this->conversationId, [
        'order_number' => 'EG-99',
        'status' => OrderStatus::Confirming,
    ]);

    $checks = ($this->grade)(new Expectations(noOrder: true));

    expect($checks[0]->passed)->toBeFalse()
        ->and($checks[0]->detail)->toBe('order EG-99 exists, status confirming.');
});

/*
 * Scoping is the whole reason the grader takes a conversation id. Two scenarios
 * running against the same seeded restaurant would otherwise grade each other's
 * orders, and the failures would be reproducible only in a full run.
 */
it('ignores orders belonging to another conversation', function (): void {
    gradedOrder($this->restaurant, 'conv_somebody_else');

    expect(($this->grade)(new Expectations(noOrder: true))[0]->passed)->toBeTrue();
});

it('ignores orders belonging to another restaurant', function (): void {
    gradedOrder(Restaurant::factory()->create(), $this->conversationId);

    expect(($this->grade)(new Expectations(noOrder: true))[0]->passed)->toBeTrue();
});

/*
 * One order per call, enforced by a unique index rather than by convention —
 * which is also what makes create_order idempotent when ElevenLabs retries a
 * tool call. Worth a test here because the grader assumes it.
 */
it('can only ever find one order, because the database allows one', function (): void {
    gradedOrder($this->restaurant, $this->conversationId, ['status' => OrderStatus::Confirmed]);

    // The second insert aborts the transaction, so this test asserts the
    // constraint and nothing else; grading is covered by its own tests above.
    expect(fn (): Order => gradedOrder($this->restaurant, $this->conversationId))
        ->toThrow(QueryException::class);
});

it('fails every order expectation at once when nothing was created', function (): void {
    $checks = ($this->grade)(new Expectations(order: ['status' => 'confirmed', 'total' => 20.59]));

    expect($checks)->toHaveCount(1)
        ->and($checks[0]->label)->toBe('an order was created')
        ->and($checks[0]->detail)->toBe('nothing was created for this conversation.');
});

// -----------------------------------------------------------------------
// The order itself
// -----------------------------------------------------------------------

it('grades the fields of an order', function (): void {
    gradedOrder($this->restaurant, $this->conversationId, [
        'status' => OrderStatus::Confirmed,
        'fulfilment_type' => FulfilmentType::Delivery,
        'payment_method' => PaymentMethod::CardLink,
        'payment_status' => PaymentStatus::Unpaid,
        'subtotal' => 1860,
        'delivery_fee' => 199,
        'total' => 2059,
        'confirmed_at' => now(),
    ]);

    $checks = ($this->grade)(new Expectations(order: [
        'status' => 'confirmed',
        'fulfilment' => 'delivery',
        'payment_method' => 'card_link',
        'payment_status' => 'unpaid',
        'subtotal' => 18.60,
        'delivery_fee' => 1.99,
        'total' => 20.59,
        'confirmed' => true,
    ]));

    expect(($this->labels)($checks, false))->toBe([]);
});

/*
 * Scenarios write money the way a person says it and the database keeps pence.
 * Getting this backwards would make every price expectation pass by accident,
 * which is the kind of bug that only surfaces when a total is wrong in
 * production.
 */
it('reads prices in major units and compares them to pence', function (): void {
    gradedOrder($this->restaurant, $this->conversationId, ['total' => 2059]);

    $wrong = ($this->grade)(new Expectations(order: ['total' => 2059]));

    expect($wrong[1]->passed)->toBeFalse()
        ->and($wrong[1]->detail)->toBe('expected 2,059.00, got 20.59');
});

it('says so when a scenario writes something that is not a price', function (): void {
    gradedOrder($this->restaurant, $this->conversationId, ['total' => 2059]);

    $checks = ($this->grade)(new Expectations(order: ['total' => 'twenty pounds']));

    expect($checks[1]->detail)->toBe('"twenty pounds" is not a price.');
});

it('reports an order field a scenario cannot expect, rather than ignoring it', function (): void {
    gradedOrder($this->restaurant, $this->conversationId);

    $checks = ($this->grade)(new Expectations(order: ['tip' => 2.00]));

    expect($checks[1]->passed)->toBeFalse()
        ->and($checks[1]->label)->toBe('order.tip')
        ->and($checks[1]->detail)->toContain('is not something a scenario can expect');
});

it('treats an unconfirmed order as unconfirmed', function (): void {
    gradedOrder($this->restaurant, $this->conversationId, [
        'status' => OrderStatus::Confirming,
        'confirmed_at' => null,
    ]);

    $checks = ($this->grade)(new Expectations(order: ['confirmed' => false]));

    expect(($this->labels)($checks, false))->toBe([]);
});

it('grades a null payment method against null', function (): void {
    gradedOrder($this->restaurant, $this->conversationId, ['payment_method' => null]);

    $checks = ($this->grade)(new Expectations(order: ['payment_method' => null]));

    expect(($this->labels)($checks, false))->toBe([]);
});

// -----------------------------------------------------------------------
// Lines
// -----------------------------------------------------------------------

it('grades each line of the order', function (): void {
    $order = gradedOrder($this->restaurant, $this->conversationId);
    $burger = MenuItem::factory()->create(['restaurant_id' => $this->restaurant->id, 'slug' => 'double-beef-burger']);

    $line = OrderItem::factory()->create([
        'order_id' => $order->id,
        'menu_item_id' => $burger->id,
        'name' => 'Double Beef Burger',
        'quantity' => 1,
        'unit_price' => 1050,
        'line_total' => 1050,
        'sort_order' => 0,
    ]);

    OrderItemModifier::factory()->create(['order_item_id' => $line->id, 'name' => 'Brioche Bun', 'sort_order' => 0]);
    OrderItemModifier::factory()->removal('Onions')->create(['order_item_id' => $line->id, 'sort_order' => 1]);

    $checks = ($this->grade)(new Expectations(order: [
        'items' => [[
            'slug' => 'double-beef-burger',
            'quantity' => 1,
            'line_total' => 10.50,
            'modifiers' => ['Brioche Bun', 'Onions'],
        ]],
    ]));

    expect(($this->labels)($checks, false))->toBe([]);
});

it('counts the lines before grading them', function (): void {
    $order = gradedOrder($this->restaurant, $this->conversationId);
    OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Chips']);

    $checks = ($this->grade)(new Expectations(order: [
        'items' => [['name' => 'Chips'], ['name' => 'Coca-Cola']],
    ]));

    expect($checks[1]->label)->toBe('number of lines on the order')
        ->and($checks[1]->detail)->toBe('expected 2, got 1');
});

it('says which line is missing rather than failing on a null', function (): void {
    $order = gradedOrder($this->restaurant, $this->conversationId);
    OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Chips']);

    $checks = ($this->grade)(new Expectations(order: [
        'items' => [['name' => 'Chips'], ['name' => 'Coca-Cola']],
    ]));

    expect(($this->labels)($checks, false))->toContain('line 2')
        ->and(end($checks)->detail)->toBe('there is no such line on the order.');
});

it('matches a dish by name as well as by slug', function (): void {
    $order = gradedOrder($this->restaurant, $this->conversationId);
    OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Halloumi Fries']);

    // Case-insensitively, because a scenario written from a real call carries
    // the words on the ticket rather than the words in the seeder.
    $checks = ($this->grade)(new Expectations(order: ['items' => [['name' => 'halloumi fries']]]));

    expect(($this->labels)($checks, false))->toBe([]);
});

it('insists a line says which dish it is', function (): void {
    $order = gradedOrder($this->restaurant, $this->conversationId);
    OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Chips', 'quantity' => 1]);

    /** @var list<Check> $checks */
    $checks = ($this->grade)(new Expectations(order: ['items' => [['quantity' => 1]]]));

    $identity = collect($checks)->firstWhere(fn (Check $check): bool => $check->label === 'line 1');

    expect($identity?->passed)->toBeFalse()
        ->and($identity?->detail)->toContain('Give it a "slug" or a "name"');
});

/*
 * "Large, no onions" and "no onions, large" are the same order. A scenario that
 * failed because the agent named the size second would be measuring the wrong
 * thing entirely.
 */
it('compares modifiers as a set rather than a sequence', function (): void {
    $order = gradedOrder($this->restaurant, $this->conversationId);
    $line = OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Chips']);

    OrderItemModifier::factory()->create(['order_item_id' => $line->id, 'name' => 'Large', 'sort_order' => 0]);
    OrderItemModifier::factory()->removal('Onions')->create(['order_item_id' => $line->id, 'sort_order' => 1]);

    $checks = ($this->grade)(new Expectations(order: [
        'items' => [['name' => 'Chips', 'modifiers' => ['onions', 'LARGE']]],
    ]));

    expect(($this->labels)($checks, false))->toBe([]);
});

it('shows both sets when the modifiers are wrong', function (): void {
    $order = gradedOrder($this->restaurant, $this->conversationId);
    $line = OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Chips']);

    OrderItemModifier::factory()->create(['order_item_id' => $line->id, 'name' => 'Large']);

    $checks = ($this->grade)(new Expectations(order: [
        'items' => [['name' => 'Chips', 'modifiers' => ['Regular']]],
    ]));

    expect(end($checks)->detail)->toBe('expected [Regular], got [Large]');
});

it('grades a line note', function (): void {
    $order = gradedOrder($this->restaurant, $this->conversationId);
    OrderItem::factory()->create(['order_id' => $order->id, 'name' => 'Chips', 'notes' => 'Extra crispy']);

    $checks = ($this->grade)(new Expectations(order: [
        'items' => [['name' => 'Chips', 'notes' => 'Extra crispy']],
    ]));

    expect(($this->labels)($checks, false))->toBe([]);
});

// -----------------------------------------------------------------------
// The conversation
// -----------------------------------------------------------------------

it('grades escalation and review against the conversation row', function (): void {
    Conversation::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'elevenlabs_conversation_id' => $this->conversationId,
        'outcome' => ConversationOutcome::Escalated,
        'needs_review' => true,
    ]);

    $checks = ($this->grade)(new Expectations(escalated: true, flaggedForReview: true));

    expect(($this->labels)($checks, false))->toBe([]);
});

it('treats a call with no conversation row as neither escalated nor flagged', function (): void {
    $checks = ($this->grade)(new Expectations(escalated: false, flaggedForReview: false));

    expect(($this->labels)($checks, false))->toBe([]);
});

it('fails an expected escalation that never happened', function (): void {
    Conversation::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'elevenlabs_conversation_id' => $this->conversationId,
        'outcome' => ConversationOutcome::OrderPlaced,
    ]);

    $checks = ($this->grade)(new Expectations(escalated: true));

    expect($checks[0]->passed)->toBeFalse()
        ->and($checks[0]->detail)->toBe('expected true, got false');
});

it('asserts nothing at all when a scenario expects nothing', function (): void {
    expect(($this->grade)(new Expectations))->toBe([]);
});
