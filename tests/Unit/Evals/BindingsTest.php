<?php

declare(strict_types=1);

use App\Services\Evals\Bindings;
use App\Services\Evals\EvalScenarioException;

it('starts with the conversation id bound', function (): void {
    $bindings = new Bindings('eval_abc');

    expect($bindings->get('conversation_id'))->toBe('eval_abc')
        ->and($bindings->all())->toBe(['conversation_id' => 'eval_abc']);
});

it('replaces a whole-string placeholder', function (): void {
    $bindings = new Bindings('eval_abc');
    $bindings->set('order_number', 'EG-1042');

    expect($bindings->fill(['order' => '{{order_number}}'], 'where'))
        ->toBe(['order' => 'EG-1042']);
});

it('interpolates a placeholder sitting inside a longer string', function (): void {
    $bindings = new Bindings('eval_abc');
    $bindings->set('name', 'Sam');

    expect($bindings->fill(['notes' => 'Ask for {{name}} at the door'], 'where'))
        ->toBe(['notes' => 'Ask for Sam at the door']);
});

it('fills placeholders nested anywhere in the parameters', function (): void {
    $bindings = new Bindings('eval_abc');
    $bindings->set('address_token', 'tok_123');

    $filled = $bindings->fill([
        'fulfilment' => 'delivery',
        'address' => ['address_token' => '{{address_token}}'],
        'items' => [['item' => 'chips', 'quantity' => 1]],
    ], 'where');

    expect($filled['address'])->toBe(['address_token' => 'tok_123'])
        ->and($filled['items'][0]['quantity'])->toBe(1);
});

it('tolerates whitespace and case inside the braces', function (): void {
    $bindings = new Bindings('eval_abc');
    $bindings->set('order_number', 'EG-1042');

    expect($bindings->fill(['a' => '{{ order_number }}', 'b' => '{{ORDER_NUMBER}}'], 'where'))
        ->toBe(['a' => 'EG-1042', 'b' => 'EG-1042']);
});

it('leaves non-strings alone', function (): void {
    $bindings = new Bindings('eval_abc');

    expect($bindings->fill(['quantity' => 2, 'confirmed' => true, 'note' => null], 'where'))
        ->toBe(['quantity' => 2, 'confirmed' => true, 'note' => null]);
});

/*
 * An unbound placeholder is the difference between a scenario that reports its
 * own mistake and one that sends "" as an address token and fails validation
 * two calls later, in a scenario about something else entirely.
 */
it('refuses to guess at a placeholder nothing has bound', function (): void {
    $bindings = new Bindings('eval_abc');

    expect(fn (): array => $bindings->fill(['address_token' => '{{address_token}}'], 'delivery call 3'))
        ->toThrow(EvalScenarioException::class, 'nothing has bound {{address_token}} yet');
});

it('names what is bound so far when a placeholder is missing', function (): void {
    $bindings = new Bindings('eval_abc');
    $bindings->set('order_number', 'EG-1042');

    expect(fn (): array => $bindings->fill(['x' => '{{nope}}'], 'where'))
        ->toThrow(EvalScenarioException::class, 'conversation_id, order_number');
});

it('captures an address token and an order number without being asked', function (): void {
    $bindings = new Bindings('eval_abc');

    $bindings->capture(['ok' => true, 'candidates' => [['address_token' => 'tok_first'], ['address_token' => 'tok_second']]]);
    $bindings->capture(['ok' => true, 'order_number' => 'EG-1042']);

    expect($bindings->get('address_token'))->toBe('tok_first')
        ->and($bindings->get('order_number'))->toBe('EG-1042');
});

/*
 * The token on a *failed* validate_address matters as much as one on a
 * success: "outside the delivery area" hands back a token so the scenario can
 * prove create_order refuses it too.
 */
it('captures a token from a failed response as well', function (): void {
    $bindings = new Bindings('eval_abc');

    $bindings->capture([
        'ok' => false,
        'error' => ['code' => 'outside_delivery_area'],
        'candidates' => [['address_token' => 'tok_far_away']],
    ]);

    expect($bindings->get('address_token'))->toBe('tok_far_away');
});

it('takes an explicit capture from anywhere in the response', function (): void {
    $bindings = new Bindings('eval_abc');

    $bindings->capture(
        ['candidates' => [['address_token' => 'tok_a'], ['address_token' => 'tok_b']]],
        ['second_token' => 'candidates.1.address_token'],
    );

    expect($bindings->get('second_token'))->toBe('tok_b');
});

it('ignores a capture that found nothing, rather than binding an empty string', function (): void {
    $bindings = new Bindings('eval_abc');

    $bindings->capture(['ok' => true], ['missing' => 'nowhere.at.all']);
    $bindings->capture(['order_number' => '']);

    expect($bindings->get('missing'))->toBeNull()
        ->and($bindings->get('order_number'))->toBeNull();
});

it('lets a later response replace an earlier binding', function (): void {
    $bindings = new Bindings('eval_abc');

    $bindings->capture(['order_number' => 'EG-1']);
    $bindings->capture(['order_number' => 'EG-2']);

    expect($bindings->get('order_number'))->toBe('EG-2');
});
