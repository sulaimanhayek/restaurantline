<?php

declare(strict_types=1);

use App\Models\Restaurant;
use Database\Seeders\DemoRestaurantSeeder;
use Database\Seeders\SampleMenuSeeder;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| kitchenline:eval
|--------------------------------------------------------------------------
|
| Output is buffered with Artisan::call rather than asserted with
| expectsOutputToContain, which matches one write at a time and in order and so
| turns every formatting change into a broken test. What matters here is which
| scenarios ran, what the exit code was, and that a failure says why.
|
*/

/**
 * @param  array<string, mixed>  $arguments
 * @return array{int, string}
 */
function runEval(array $arguments = []): array
{
    $status = Artisan::call('kitchenline:eval', $arguments);

    return [$status, Artisan::output()];
}

beforeEach(function (): void {
    $this->seed(DemoRestaurantSeeder::class);
    $this->seed(SampleMenuSeeder::class);

    $this->scenarios = dirname(__DIR__, 3).'/evals/scenarios';
});

it('runs every shipped scenario and exits zero', function (): void {
    [$status, $output] = runEval(['--path' => $this->scenarios]);

    expect($status)->toBe(0)
        ->and($output)->toContain('fake')
        ->and($output)->toContain('all passing.')
        ->and($output)->toContain('Ember Grill');
});

it('runs a single scenario named on the command line', function (): void {
    [$status, $output] = runEval(['scenario' => ['escalates-to-a-human'], '--path' => $this->scenarios]);

    expect($status)->toBe(0)
        ->and($output)->toContain('1 scenarios')
        ->and($output)->not->toContain('A delivery order');
});

it('selects scenarios by tag', function (): void {
    [$status, $output] = runEval(['--tag' => ['safety'], '--path' => $this->scenarios]);

    expect($status)->toBe(0)
        ->and($output)->toContain('all passing.');

    // Every safety scenario, and nothing that is only a happy path.
    expect($output)->not->toContain('A collection order');
});

it('says so when nothing matched, rather than reporting a clean run of nothing', function (): void {
    [$status, $output] = runEval(['--tag' => ['pastry'], '--path' => $this->scenarios]);

    expect($status)->toBe(1)
        ->and($output)->toContain('matched');
});

it('refuses a mode that does not exist', function (): void {
    [$status, $output] = runEval(['--mode' => 'sideways', '--path' => $this->scenarios]);

    expect($status)->toBe(1)
        ->and($output)->toContain('There is no "sideways" mode');
});

it('says where the scenarios were supposed to be', function (): void {
    [$status, $output] = runEval(['--path' => '/nowhere/at/all']);

    expect($status)->toBe(1)
        ->and($output)->toContain('there is no such directory');
});

it('names the restaurant it cannot find', function (): void {
    [$status, $output] = runEval(['--restaurant' => 'not-a-restaurant', '--path' => $this->scenarios]);

    expect($status)->toBe(1)
        ->and($output)->toContain('No restaurant with the slug "not-a-restaurant"');
});

it('points at the seeder when there is no restaurant at all', function (): void {
    Restaurant::query()->delete();

    [$status, $output] = runEval(['--path' => $this->scenarios]);

    expect($status)->toBe(1)
        ->and($output)->toContain('migrate --seed');
});

/*
 * A failing scenario has to say which check failed and show the calls that got
 * there. An eval that reports only "FAIL" is an eval nobody can act on.
 */
it('prints the failed check and the call log when a scenario fails', function (): void {
    $directory = sys_get_temp_dir().'/restaurantline-eval-'.bin2hex(random_bytes(6));
    mkdir($directory);

    file_put_contents($directory.'/wrong-total.json', (string) json_encode([
        'title' => 'A scenario with the wrong total in it',
        'calls' => [[
            'tool' => 'create_order',
            'params' => [
                'fulfilment' => 'collection',
                'customer' => ['phone_number' => '07700900190', 'name' => 'Sam'],
                'items' => [['item' => 'chips', 'quantity' => 1]],
            ],
            'expect' => ['ok' => true],
        ]],
        'expect' => ['order' => ['total' => 99.99]],
    ]));

    [$status, $output] = runEval(['--path' => $directory]);

    expect($status)->toBe(1)
        ->and($output)->toContain('FAIL')
        ->and($output)->toContain('total')
        ->and($output)->toContain('expected 99.99, got 3.00')
        ->and($output)->toContain('create_order');

    array_map(unlink(...), glob($directory.'/*') ?: []);
    rmdir($directory);
});

/*
 * An ERROR is a problem with the eval, not a report about the agent, and the
 * summary says so — otherwise a renamed route reads in CI as a regression in
 * the ordering flow.
 */
it('separates a scenario that could not run from one that failed', function (): void {
    $directory = sys_get_temp_dir().'/restaurantline-eval-'.bin2hex(random_bytes(6));
    mkdir($directory);

    file_put_contents($directory.'/invented-tool.json', (string) json_encode([
        'title' => 'A tool the agent does not have',
        'calls' => [['tool' => 'order_a_taxi']],
    ]));

    [$status, $output] = runEval(['--path' => $directory]);

    expect($status)->toBe(1)
        ->and($output)->toContain('ERROR')
        ->and($output)->toContain('there is no such tool')
        ->and($output)->toContain('a problem with the eval rather than with the agent');

    array_map(unlink(...), glob($directory.'/*') ?: []);
    rmdir($directory);
});

it('warns before running live mode, because live mode costs money', function (): void {
    // No agent is provisioned, so every scenario errors — which is itself the
    // right answer, and the warning has to come before any of that.
    [$status, $output] = runEval(['--mode' => 'live', '--path' => $this->scenarios]);

    expect($status)->toBe(1)
        ->and($output)->toContain('billed per scenario')
        ->and($output)->toContain('kitchenline:provision');
});
