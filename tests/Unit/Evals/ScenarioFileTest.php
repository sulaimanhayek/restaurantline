<?php

declare(strict_types=1);

use App\Services\Evals\EvalScenarioException;
use App\Services\Evals\ScenarioFile;

/*
|--------------------------------------------------------------------------
| Reading a scenario
|--------------------------------------------------------------------------
|
| Everything this reads is a file in the repository, so none of the strictness
| here is a security boundary. It is the difference between "menu.json call 2
| has no tool" and a null dereference inside the runner twenty minutes later,
| which is the whole reason the reader is a separate class.
|
*/

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/restaurantline-scenarios-'.bin2hex(random_bytes(6));

    mkdir($this->directory);

    $this->write = function (string $name, array|string $contents): string {
        $path = $this->directory.'/'.$name;

        file_put_contents($path, is_string($contents) ? $contents : (string) json_encode($contents));

        return $path;
    };
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory.'/*') ?: []);

    rmdir($this->directory);
});

it('reads a scenario with everything filled in', function (): void {
    $path = ($this->write)('delivery-order.json', [
        'title' => 'A delivery order',
        'description' => 'Two burgers and a Coke.',
        'tags' => ['smoke', 'delivery'],
        'at' => '2026-09-14 19:00',
        'caller' => 'You want two burgers.',
        'calls' => [
            ['tool' => 'quote_order', 'params' => ['fulfilment' => 'delivery'], 'expect' => ['ok' => true]],
        ],
        'expect' => ['no_order' => true],
        'criteria' => [['name' => 'Read it back', 'goal' => 'The agent read the order back.']],
    ]);

    $scenario = ScenarioFile::read($path);

    expect($scenario->name)->toBe('delivery-order')
        ->and($scenario->title)->toBe('A delivery order')
        ->and($scenario->description)->toBe('Two burgers and a Coke.')
        ->and($scenario->tags)->toBe(['smoke', 'delivery'])
        ->and($scenario->at)->toBe('2026-09-14 19:00')
        ->and($scenario->caller)->toBe('You want two burgers.')
        ->and($scenario->calls)->toHaveCount(1)
        ->and($scenario->calls[0]->tool)->toBe('quote_order')
        ->and($scenario->calls[0]->params)->toBe(['fulfilment' => 'delivery'])
        ->and($scenario->calls[0]->expect)->toBe(['ok' => true])
        ->and($scenario->expect->noOrder)->toBeTrue()
        ->and($scenario->path)->toBe($path);
});

it('names a scenario after its file, and titles it after its name when it has no title', function (): void {
    $scenario = ScenarioFile::read(($this->write)('below-minimum-order.json', ['calls' => []]));

    expect($scenario->name)->toBe('below-minimum-order')
        ->and($scenario->title)->toBe('Below Minimum Order');
});

/*
 * A caller's brief is a paragraph, and a paragraph on one JSON line is a
 * paragraph nobody edits. Letting any string be a list of lines is most of why
 * these files are pleasant to work in.
 */
it('joins a list of lines into one string', function (): void {
    $scenario = ScenarioFile::read(($this->write)('x.json', [
        'caller' => ['It is late.', 'You want chips.'],
        'description' => ['One', 'Two'],
        'calls' => [],
    ]));

    expect($scenario->caller)->toBe("It is late.\nYou want chips.")
        ->and($scenario->description)->toBe("One\nTwo");
});

it('derives a criterion id from its name', function (): void {
    $scenario = ScenarioFile::read(($this->write)('x.json', [
        'criteria' => [
            ['name' => 'Never asked for card details', 'goal' => ['The agent did not ask', 'for a card number.']],
        ],
        'calls' => [],
    ]));

    expect($scenario->criteria[0])->toBe([
        'id' => 'never_asked_for_card_details',
        'name' => 'Never asked for card details',
        'conversation_goal_prompt' => "The agent did not ask\nfor a card number.",
    ]);
});

it('defaults a call to no params, no expectations and no captures', function (): void {
    $scenario = ScenarioFile::read(($this->write)('x.json', ['calls' => [['tool' => 'opening_hours']]]));

    expect($scenario->calls[0]->params)->toBe([])
        ->and($scenario->calls[0]->expect)->toBe([])
        ->and($scenario->calls[0]->capture)->toBe([])
        ->and($scenario->calls[0]->note)->toBeNull();
});

it('reads every scenario in a directory, in filename order', function (): void {
    ($this->write)('b-second.json', ['calls' => []]);
    ($this->write)('a-first.json', ['calls' => []]);
    ($this->write)('notes.txt', 'ignored');

    expect(array_map(fn ($s): string => $s->name, ScenarioFile::all($this->directory)))
        ->toBe(['a-first', 'b-second']);
});

it('answers whether a scenario carries a tag', function (): void {
    $scenario = ScenarioFile::read(($this->write)('x.json', ['tags' => ['safety'], 'calls' => []]));

    expect($scenario->hasTag('safety'))->toBeTrue()
        ->and($scenario->hasTag('smoke'))->toBeFalse();
});

// -----------------------------------------------------------------------
// The ways a file can be wrong
// -----------------------------------------------------------------------

it('says which directory does not exist', function (): void {
    expect(fn () => ScenarioFile::all($this->directory.'/nope'))
        ->toThrow(EvalScenarioException::class, 'there is no such directory');
});

it('says which file it could not read', function (): void {
    expect(fn () => ScenarioFile::read($this->directory.'/missing.json'))
        ->toThrow(EvalScenarioException::class, 'no such file');
});

it('reports a JSON syntax error with the file name', function (): void {
    $path = ($this->write)('broken.json', '{"calls": [,]}');

    expect(fn () => ScenarioFile::read($path))
        ->toThrow(EvalScenarioException::class, 'broken.json: this is not valid JSON');
});

it('refuses a top-level value that is not an object', function (): void {
    expect(fn () => ScenarioFile::read(($this->write)('x.json', '"a string"')))
        ->toThrow(EvalScenarioException::class, 'expected an object at the top level');
});

it('names the call that has no tool', function (): void {
    $path = ($this->write)('x.json', ['calls' => [['tool' => 'quote_order'], ['params' => []]]]);

    expect(fn () => ScenarioFile::read($path))
        ->toThrow(EvalScenarioException::class, 'x.json call 2: has no "tool"');
});

it('refuses calls that are not a list', function (): void {
    expect(fn () => ScenarioFile::read(($this->write)('x.json', ['calls' => 'quote_order'])))
        ->toThrow(EvalScenarioException::class, '"calls" must be a list of tool calls');
});

it('refuses a criterion missing its goal', function (): void {
    $path = ($this->write)('x.json', ['criteria' => [['name' => 'Read it back']], 'calls' => []]);

    expect(fn () => ScenarioFile::read($path))
        ->toThrow(EvalScenarioException::class, 'x.json criterion 1: needs both a "name" and a "goal"');
});

it('refuses a title that is not text', function (): void {
    expect(fn () => ScenarioFile::read(($this->write)('x.json', ['title' => 42, 'calls' => []])))
        ->toThrow(EvalScenarioException::class, '"title" must be a string');
});
