<?php

declare(strict_types=1);

use App\Services\Evals\Check;
use App\Services\Evals\EvalScenarioException;
use App\Services\Evals\Expectations;

it('expects nothing by default', function (): void {
    $expect = new Expectations;

    expect($expect->toolsCalled)->toBe([])
        ->and($expect->toolsNotCalled)->toBe([])
        ->and($expect->order)->toBeNull()
        ->and($expect->noOrder)->toBeNull()
        ->and($expect->escalated)->toBeNull()
        ->and($expect->flaggedForReview)->toBeNull();
});

it('reads every expectation a scenario can state', function (): void {
    $expect = Expectations::fromArray([
        'tools_called' => ['quote_order', 'create_order'],
        'tools_not_called' => ['escalate_to_human'],
        'order' => ['status' => 'confirmed', 'total' => 20.59],
        'no_order' => false,
        'escalated' => false,
        'flagged_for_review' => true,
    ], 'x.json');

    expect($expect->toolsCalled)->toBe(['quote_order', 'create_order'])
        ->and($expect->toolsNotCalled)->toBe(['escalate_to_human'])
        ->and($expect->order)->toBe(['status' => 'confirmed', 'total' => 20.59])
        ->and($expect->noOrder)->toBeFalse()
        ->and($expect->escalated)->toBeFalse()
        ->and($expect->flaggedForReview)->toBeTrue();
});

/*
 * The failure this guards against is the quiet one. A misspelt expectation is
 * an assertion that never runs, and a scenario that passes while reporting it
 * checked something it did not is worse than no scenario at all.
 */
it('refuses an expectation it does not understand', function (): void {
    expect(fn (): Expectations => Expectations::fromArray(['no_orders' => true], 'delivery-order.json'))
        ->toThrow(EvalScenarioException::class, 'does not understand the expectation "no_orders"');
});

it('lists the expectations it does understand when it refuses one', function (): void {
    expect(fn (): Expectations => Expectations::fromArray(['total' => 10], 'x.json'))
        ->toThrow(EvalScenarioException::class, 'tools_called, tools_not_called, order, no_order');
});

it('refuses a tool list that is not a list', function (): void {
    expect(fn (): Expectations => Expectations::fromArray(['tools_called' => 'quote_order'], 'x.json'))
        ->toThrow(EvalScenarioException::class, '"tools_called" must be a list of tool names');
});

it('ignores a non-boolean where a boolean belongs rather than guessing at it', function (): void {
    // "yes" is not false, and treating it as false would assert the opposite of
    // what the file says. Leaving it unset asserts nothing, which is honest.
    $expect = Expectations::fromArray(['no_order' => 'yes'], 'x.json');

    expect($expect->noOrder)->toBeNull();
});

// -----------------------------------------------------------------------
// Check
// -----------------------------------------------------------------------

it('renders both sides of a failed comparison', function (): void {
    expect(Check::equals('order status', 'confirmed', 'confirming')->detail)
        ->toBe('expected "confirmed", got "confirming"');
});

it('passes a comparison of equal values and says nothing more', function (): void {
    $check = Check::equals('order status', 'confirmed', 'confirmed');

    expect($check->passed)->toBeTrue()
        ->and($check->detail)->toBeNull();
});

it('is strict about types, because 0 and false are different answers', function (): void {
    expect(Check::equals('confirmed', false, 0)->passed)->toBeFalse()
        ->and(Check::equals('quantity', 1, '1')->passed)->toBeFalse();
});

it('renders values a person can read', function (): void {
    expect(Check::render('chips'))->toBe('"chips"')
        ->and(Check::render(true))->toBe('true')
        ->and(Check::render(false))->toBe('false')
        ->and(Check::render(null))->toBe('null')
        ->and(Check::render(1850))->toBe('1850')
        ->and(Check::render(['a' => 1]))->toBe('{"a":1}');
});
