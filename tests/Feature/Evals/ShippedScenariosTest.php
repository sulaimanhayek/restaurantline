<?php

declare(strict_types=1);

use App\Models\Restaurant;
use App\Services\Evals\Check;
use App\Services\Evals\Expectations;
use App\Services\Evals\ReplayRunner;
use App\Services\Evals\Scenario;
use App\Services\Evals\ScenarioCall;
use App\Services\Evals\ScenarioFile;
use App\Services\Evals\ScenarioResult;
use Database\Seeders\DemoRestaurantSeeder;
use Database\Seeders\SampleMenuSeeder;

/*
|--------------------------------------------------------------------------
| The scenarios this repository ships
|--------------------------------------------------------------------------
|
| Every file in evals/scenarios, replayed against a freshly seeded database,
| with every check asserted to pass.
|
| This is the test that keeps the eval harness honest, and it earns its keep in
| both directions. A scenario whose totals have drifted out of step with the
| seeded menu fails here, in CI, rather than the first time somebody runs the
| command by hand and mistrusts the output. And a change to pricing, matching or
| the order lifecycle that no unit test covers shows up as an order with the
| wrong total, which is the failure a restaurant would have noticed.
|
| It runs the same code the command runs — the same runner, the same tool URLs
| out of ToolDefinitions, the same middleware — so "the evals pass" means the
| same thing here as it does on a laptop.
|
*/

beforeEach(function (): void {
    $this->seed(DemoRestaurantSeeder::class);
    $this->seed(SampleMenuSeeder::class);
});

/**
 * Not `base_path()`: a dataset is resolved while the test files are being
 * collected, and leaning on a booted application at that point is a class of
 * flake that is tedious to diagnose for no benefit.
 */
function shippedScenarios(): string
{
    return dirname(__DIR__, 3).'/evals/scenarios';
}

/**
 * Everything the runner saw, formatted for somebody reading a CI log.
 *
 * A bare "expected true, got false" on a scenario carrying twenty assertions is
 * half an hour with `dd()`. The call log says what the agent did; the failures
 * say which of the twenty broke and what the database held instead.
 */
function explainScenario(ScenarioResult $result): string
{
    $lines = ['', 'scenario  '.$result->scenario->name, ''];

    foreach ($result->log as $line) {
        $lines[] = '  '.$line;
    }

    if ($result->error !== null) {
        $lines[] = '';
        $lines[] = '  the scenario could not run: '.$result->error;
    }

    foreach ($result->failures() as $failure) {
        $lines[] = sprintf('  ✗ %s — %s', $failure->label, (string) $failure->detail);
    }

    return implode("\n", $lines)."\n";
}

dataset('shipped scenarios', function (): Generator {
    foreach (ScenarioFile::all(shippedScenarios()) as $scenario) {
        yield $scenario->name => [$scenario];
    }
});

it('replays every shipped scenario against a seeded restaurant', function (Scenario $scenario): void {
    $result = app(ReplayRunner::class)->run(Restaurant::query()->firstOrFail(), $scenario);

    expect($result->error)->toBeNull(explainScenario($result));
    expect($result->failures())->toBe([], explainScenario($result));
    expect($result->checks)->not->toBeEmpty('it asserted nothing at all.');
})->with('shipped scenarios');

it('covers every design constraint the README promises', function (): void {
    $scenarios = ScenarioFile::all(shippedScenarios());

    // These four are the reason the application is shaped the way it is, and
    // each has a scenario naming it. A tag going missing should fail a test
    // rather than quietly shrink what CI covers.
    $tags = collect($scenarios)->flatMap(fn (Scenario $s): array => $s->tags)->unique()->values();

    expect($tags)->toContain('safety')
        ->and($tags)->toContain('smoke')
        ->and($tags)->toContain('delivery')
        ->and($tags)->toContain('collection');

    $titles = collect($scenarios)->map(fn (Scenario $s): string => $s->name)->all();

    expect($titles)->toContain('refuses-a-card-number')
        ->and($titles)->toContain('order-read-back-before-confirming')
        ->and($titles)->toContain('address-not-confirmed')
        ->and($titles)->toContain('escalates-to-a-human');
});

it('gives every scenario what live mode needs as well', function (): void {
    // Fake mode is the one that runs in CI, so it is the one that stays
    // correct without anybody trying. A scenario that quietly lost its caller
    // brief still passes here and does nothing at all against a real agent,
    // which is the worst of both: a green run and no coverage.
    foreach (ScenarioFile::all(shippedScenarios()) as $scenario) {
        expect($scenario->caller)->not->toBeNull($scenario->name.' has no "caller", so live mode cannot run it.');
        expect($scenario->criteria)->not->toBeEmpty($scenario->name.' has no "criteria", so live mode grades nothing.');
        expect($scenario->description)->not->toBeNull($scenario->name.' has no "description".');
        expect($scenario->tags)->not->toBeEmpty($scenario->name.' has no "tags", so --tag cannot select it.');
    }
});

it('reports a missing tool as an error rather than a failed check', function (): void {
    $scenario = new Scenario(
        name: 'invented-tool',
        title: 'A tool the agent does not have',
        description: null,
        caller: null,
        calls: [new ScenarioCall(tool: 'order_a_taxi', params: [])],
        expect: new Expectations,
    );

    $result = app(ReplayRunner::class)->run(Restaurant::query()->firstOrFail(), $scenario);

    expect($result->passed())->toBeFalse()
        ->and($result->error)->toContain('there is no such tool')
        ->and($result->checks)->toBe([]);
});

it('says so when a scenario has nothing to replay', function (): void {
    $result = app(ReplayRunner::class)->run(Restaurant::query()->firstOrFail(), new Scenario(
        name: 'empty',
        title: 'Nothing to do',
        description: null,
        caller: 'Rings up and says nothing at all.',
        calls: [],
        expect: new Expectations,
    ));

    expect($result->error)->toContain('--mode=live');
});

it('restores the real clock after a scenario has moved it', function (): void {
    $before = now()->toDateTimeString();

    app(ReplayRunner::class)->run(
        Restaurant::query()->firstOrFail(),
        ScenarioFile::read(shippedScenarios().'/closed-at-three-in-the-morning.json'),
    );

    // The runner sets Carbon's test clock to a moment the kitchen is open, or
    // to whatever the scenario asked for. Leaking that into the next test would
    // be a spectacularly confusing failure somewhere else in the suite.
    expect(now()->toDateTimeString())->not->toBe('2026-01-01 03:00:00')
        ->and(abs(now()->diffInSeconds($before)))->toBeLessThan(60);
});

it('renders a check failure with both sides of the comparison', function (): void {
    $check = Check::equals('order status', 'confirmed', 'confirming');

    expect($check->passed)->toBeFalse()
        ->and($check->detail)->toBe('expected "confirmed", got "confirming"');
});
