<?php

declare(strict_types=1);

use App\Models\OpeningHour;
use App\Models\Restaurant;

/**
 * `/hours` and `/availability`. Both answer a question about the clock; they
 * differ in that `/hours` is informational and always succeeds, while
 * `/availability` is a gate the agent is meant to pass through before taking an
 * order.
 *
 * Every assertion about a spoken string here is load-bearing. "22:30" read
 * aloud by a language model comes out as "twenty two thirty", which is the
 * whole reason these fields exist (#0016).
 */
beforeEach(function (): void {
    $this->restaurant = restaurant(['timezone' => 'Europe/London']);
});

/**
 * Dinner service, every day, 17:00–22:30.
 */
function dinnerEveryDay(Restaurant $restaurant): void
{
    foreach (range(0, 6) as $dayOfWeek) {
        OpeningHour::factory()->for($restaurant)->dinner()->forDay($dayOfWeek)->create();
    }
}

it('reads the closing time as a person would say it', function (): void {
    dinnerEveryDay($this->restaurant);
    $this->travelTo($this->restaurant->now()->setTime(19, 0));

    agentGet('hours')
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('open_now', true)
        ->assertJsonPath('closes_at', '22:30')
        ->assertJsonPath('closes_at_spoken', '10:30pm')
        ->assertJsonPath('timezone', 'Europe/London');
});

it('says when it next opens rather than leaving the agent to work it out', function (): void {
    dinnerEveryDay($this->restaurant);
    $this->travelTo($this->restaurant->now()->setTime(23, 30));

    agentGet('hours')
        ->assertOk()
        ->assertJsonPath('open_now', false)
        ->assertJsonPath('closes_at', null)
        ->assertJsonPath('next_opens_spoken', 'tomorrow at 5pm');
});

it('calls the next opening "later today" when it has not happened yet', function (): void {
    dinnerEveryDay($this->restaurant);
    $this->travelTo($this->restaurant->now()->setTime(9, 0));

    agentGet('hours')->assertJsonPath('next_opens_spoken', 'later today at 5pm');
});

it('names the day when the next opening is neither today nor tomorrow', function (): void {
    // A kitchen open on Saturdays only, asked on the Monday before.
    OpeningHour::factory()->for($this->restaurant)->dinner()->forDay(6)->create();
    $this->travelTo($this->restaurant->now()->next('Monday')->setTime(9, 0));

    agentGet('hours')->assertJsonPath('next_opens_spoken', 'on Saturday at 5pm');
});

it('returns no next opening at all when the kitchen never opens', function (): void {
    agentGet('hours')
        ->assertOk()
        ->assertJsonPath('open_now', false)
        ->assertJsonPath('next_opens_at', null)
        ->assertJsonPath('next_opens_spoken', null);
});

it('lists the week starting on Monday, with closed days named', function (): void {
    OpeningHour::factory()->for($this->restaurant)->dinner()->forDay(1)->create();

    $week = agentGet('hours')->json('week');

    expect(rows($week)->pluck('day')->all())
        ->toBe(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'])
        ->and($week[0]['spoken'])->toBe('5pm to 10:30pm')
        ->and($week[1]['closed'])->toBeTrue()
        ->and($week[1]['spoken'])->toBe('closed');
});

it('joins two windows in a day into one sentence', function (): void {
    OpeningHour::factory()->for($this->restaurant)->lunch()->forDay(1)->create();
    OpeningHour::factory()->for($this->restaurant)->dinner()->forDay(1)->create();

    expect(rows(agentGet('hours')->json('week'))->firstWhere('day_of_week', 1)['spoken'])
        ->toBe('midday to 3pm and 5pm to 10:30pm');
});

it('stays open past midnight when the window says it closes next day', function (): void {
    foreach (range(0, 6) as $dayOfWeek) {
        OpeningHour::factory()->for($this->restaurant)->lateNight()->forDay($dayOfWeek)->create();
    }

    $this->travelTo($this->restaurant->now()->setTime(1, 0));

    agentGet('hours')->assertJsonPath('open_now', true)->assertJsonPath('closes_at', '02:00');
});

it('quotes a wait and a ready time when the kitchen is open', function (): void {
    dinnerEveryDay($this->restaurant);
    $this->restaurant->update(['delivery_prep_minutes' => 45, 'collection_prep_minutes' => 20]);
    $this->travelTo($this->restaurant->now()->setTime(19, 0));

    agentPost('availability', ['conversation_id' => 'call-1', 'fulfilment' => 'delivery'])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('open_now', true)
        ->assertJsonPath('fulfilment', 'delivery')
        ->assertJsonPath('estimated_minutes', 45)
        ->assertJsonPath('estimated_minutes_spoken', 'about 45 minutes')
        ->assertJsonPath('ready_at_spoken', '7:45pm')
        ->assertJsonPath('closes_at', '22:30');
});

it('quotes the collection wait for a collection order', function (): void {
    dinnerEveryDay($this->restaurant);
    $this->restaurant->update(['delivery_prep_minutes' => 45, 'collection_prep_minutes' => 20]);
    $this->travelTo($this->restaurant->now()->setTime(19, 0));

    agentPost('availability', ['conversation_id' => 'call-1', 'fulfilment' => 'collection'])
        ->assertJsonPath('estimated_minutes', 20);
});

it('refuses when the kitchen is shut, and says when it opens', function (): void {
    dinnerEveryDay($this->restaurant);
    $this->travelTo($this->restaurant->now()->setTime(23, 30));

    agentPost('availability', ['conversation_id' => 'call-1', 'fulfilment' => 'delivery'])
        ->assertOk()
        ->assertJsonPath('ok', false)
        ->assertJsonPath('error.code', 'closed')
        ->assertJsonPath('error.say', "I'm sorry, we're closed at the moment. We open again tomorrow at 5pm.")
        ->assertJsonPath('accepting_orders', true);
});

/**
 * The distinction the endpoint exists to preserve. A manager pausing orders
 * during a rush is a twenty-minute problem; a closed kitchen is a tomorrow
 * problem. Telling a caller the wrong one loses the order either way.
 */
it('reports a paused kitchen separately from a closed one', function (): void {
    dinnerEveryDay($this->restaurant);
    $this->restaurant->update(['is_accepting_orders' => false]);
    $this->travelTo($this->restaurant->now()->setTime(19, 0));

    agentPost('availability', ['conversation_id' => 'call-1', 'fulfilment' => 'delivery'])
        ->assertJsonPath('error.code', 'not_accepting_orders')
        ->assertJsonPath('open_now', true)
        ->assertJsonPath('accepting_orders', false);
});

it('rejects a fulfilment type that is neither delivery nor collection', function (): void {
    agentPost('availability', ['conversation_id' => 'call-1', 'fulfilment' => 'drone'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'invalid_request');
});
