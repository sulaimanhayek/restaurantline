<?php

declare(strict_types=1);

use App\Enums\ConversationOutcome;
use App\Models\Conversation;
use App\Models\Order;
use App\Services\ElevenLabs\ElevenLabsClient;
use App\Services\ElevenLabs\ElevenLabsException;
use App\Services\ElevenLabs\FakeElevenLabsClient;
use App\Services\Evals\Check;
use App\Services\Evals\Expectations;
use App\Services\Evals\OutcomeGrader;
use App\Services\Evals\Scenario;
use App\Services\Evals\SimulationRunner;
use Mockery\MockInterface;

/*
|--------------------------------------------------------------------------
| Live mode
|--------------------------------------------------------------------------
|
| ElevenLabs runs the conversation; this grades what came back. These tests put
| a canned simulation in front of the runner, because the thing worth testing on
| our side is the grading and the conversation-id recovery — whether a real
| agent behaves is what the command is for, and it costs money to ask.
|
*/

beforeEach(function (): void {
    $this->restaurant = restaurant(['slug' => 'ember-grill', 'elevenlabs_agent_id' => 'agent_1234']);

    $this->scenario = fn (array $overrides = []): Scenario => new Scenario(
        name: $overrides['name'] ?? 'delivery-order',
        title: 'A delivery order',
        description: null,
        caller: array_key_exists('caller', $overrides) ? $overrides['caller'] : 'You want a burger.',
        calls: [],
        expect: $overrides['expect'] ?? new Expectations,
        criteria: $overrides['criteria'] ?? [],
    );

    $this->simulating = function (array $simulation): SimulationRunner {
        /** @var ElevenLabsClient&MockInterface $client */
        $client = Mockery::mock(ElevenLabsClient::class);
        $client->shouldReceive('simulateConversation')->andReturn($simulation);

        $this->client = $client;

        return new SimulationRunner($client, app(OutcomeGrader::class));
    };

    $this->labels = fn (array $checks, bool $passed): array => array_values(array_map(
        fn (Check $check): string => $check->label,
        array_filter($checks, fn (Check $check): bool => $check->passed === $passed),
    ));
});

/**
 * @param  list<array<string, mixed>>  $turns
 * @return array<string, mixed>
 */
function transcript(array $turns): array
{
    return ['simulated_conversation' => $turns, 'analysis' => ['call_successful' => 'success', 'transcript_summary' => 'A call.']];
}

// -----------------------------------------------------------------------
// What it refuses to start
// -----------------------------------------------------------------------

it('refuses a scenario with nobody to play the caller', function (): void {
    $runner = ($this->simulating)(transcript([]));

    $result = $runner->run($this->restaurant, ($this->scenario)(['caller' => null]));

    expect($result->error)->toContain('has no "caller"')
        ->and($result->error)->toContain('fake mode');
});

it('points at provisioning when the restaurant has no agent', function (): void {
    $this->restaurant->forceFill(['elevenlabs_agent_id' => null])->save();

    $result = ($this->simulating)(transcript([]))->run($this->restaurant, ($this->scenario)());

    expect($result->error)->toContain('kitchenline:provision');
});

it('reports an ElevenLabs failure as an error rather than a failed check', function (): void {
    /** @var ElevenLabsClient&MockInterface $client */
    $client = Mockery::mock(ElevenLabsClient::class);
    $client->shouldReceive('simulateConversation')->andThrow(new ElevenLabsException('402 Payment Required'));

    $runner = new SimulationRunner($client, app(OutcomeGrader::class));

    $result = $runner->run($this->restaurant, ($this->scenario)());

    expect($result->passed())->toBeFalse()
        ->and($result->error)->toBe('402 Payment Required')
        ->and($result->checks)->toBe([]);
});

/*
 * The one method the fake client refuses rather than pretends. A made-up
 * transcript, graded and printed as a pass, is worse than no answer: the entire
 * value of a live eval is that a real model made real decisions.
 */
it('will not pretend to simulate a conversation without an account', function (): void {
    $fake = app(FakeElevenLabsClient::class);

    expect(fn (): array => $fake->simulateConversation('agent_1234', []))
        ->toThrow(ElevenLabsException::class, 'nothing sensible to fake');

    $result = (new SimulationRunner($fake, app(OutcomeGrader::class)))
        ->run($this->restaurant, ($this->scenario)());

    expect($result->error)->toContain('ELEVENLABS_DRIVER=api')
        ->and($result->error)->toContain('--mode=live');
});

// -----------------------------------------------------------------------
// Grading the judge's verdicts
// -----------------------------------------------------------------------

it('passes a criterion the judge marked a success', function (): void {
    $runner = ($this->simulating)([
        'simulated_conversation' => [],
        'analysis' => [
            'call_successful' => 'success',
            'transcript_summary' => 'A call.',
            'evaluation_criteria_results' => [
                'read_the_order_back' => ['result' => 'success', 'rationale' => 'It did.'],
            ],
        ],
    ]);

    $result = $runner->run($this->restaurant, ($this->scenario)([
        'criteria' => [[
            'id' => 'read_the_order_back',
            'name' => 'Read the order back',
            'conversation_goal_prompt' => 'The agent read the order back.',
        ]],
    ]));

    expect(($this->labels)($result->checks, true))->toBe(['Read the order back']);
});

it('fails a criterion the judge marked a failure, and quotes the rationale', function (): void {
    $runner = ($this->simulating)([
        'simulated_conversation' => [],
        'analysis' => [
            'evaluation_criteria_results' => [
                'read_the_order_back' => ['result' => 'failure', 'rationale' => 'It gave a total and nothing else.'],
            ],
        ],
    ]);

    $result = $runner->run($this->restaurant, ($this->scenario)([
        'criteria' => [[
            'id' => 'read_the_order_back',
            'name' => 'Read the order back',
            'conversation_goal_prompt' => 'The agent read the order back.',
        ]],
    ]));

    expect($result->checks[0]->passed)->toBeFalse()
        ->and($result->checks[0]->detail)->toBe('failure — It gave a total and nothing else.');
});

/*
 * A judge that could not tell whether the agent read the order back is not
 * evidence that it did.
 */
it('treats an unknown verdict as a failure', function (): void {
    $runner = ($this->simulating)([
        'simulated_conversation' => [],
        'analysis' => ['evaluation_criteria_results' => ['x' => ['result' => 'unknown']]],
    ]);

    $result = $runner->run($this->restaurant, ($this->scenario)([
        'criteria' => [['id' => 'x', 'name' => 'Something', 'conversation_goal_prompt' => '…']],
    ]));

    expect($result->checks[0]->passed)->toBeFalse()
        ->and($result->checks[0]->detail)->toContain('unknown');
});

it('fails a criterion the judge said nothing about', function (): void {
    $runner = ($this->simulating)(transcript([]));

    $result = $runner->run($this->restaurant, ($this->scenario)([
        'criteria' => [['id' => 'x', 'name' => 'Something', 'conversation_goal_prompt' => '…']],
    ]));

    expect($result->checks[0]->detail)->toBe('the judge returned no verdict on this one.');
});

// -----------------------------------------------------------------------
// Working out which call this was
// -----------------------------------------------------------------------

/*
 * ElevenLabs assigns the conversation id and does not hand it back, so it has
 * to be recovered from what the agent did with it. An order carries it on the
 * row; escalation echoes it directly, which is the only way to identify a call
 * that deliberately left no order behind.
 */
it('finds the conversation through the order the agent created', function (): void {
    Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'order_number' => 'EG-4242',
        'elevenlabs_conversation_id' => 'conv_from_the_platform',
        'total' => 2059,
    ]);

    $runner = ($this->simulating)(transcript([[
        'role' => 'agent',
        'tool_results' => [[
            'request_id' => 'r1',
            'tool_name' => 'create_order',
            'result_value' => (string) json_encode(['ok' => true, 'order_number' => 'EG-4242']),
            'is_error' => false,
            'tool_has_been_called' => true,
        ]],
    ]]));

    $result = $runner->run($this->restaurant, ($this->scenario)([
        'expect' => new Expectations(order: ['total' => 20.59]),
    ]));

    expect(($this->labels)($result->checks, false))->toBe([]);
});

it('finds the conversation through an escalation that left no order', function (): void {
    Conversation::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'elevenlabs_conversation_id' => 'conv_escalated',
        'outcome' => ConversationOutcome::Escalated,
        'needs_review' => true,
    ]);

    $runner = ($this->simulating)(transcript([[
        'role' => 'agent',
        'tool_results' => [[
            'request_id' => 'r1',
            'tool_name' => 'escalate_to_human',
            'result_value' => (string) json_encode(['ok' => true, 'conversation' => 'conv_escalated']),
            'is_error' => false,
            'tool_has_been_called' => true,
        ]],
    ]]));

    $result = $runner->run($this->restaurant, ($this->scenario)([
        'expect' => new Expectations(noOrder: true, escalated: true, flaggedForReview: true),
    ]));

    expect(($this->labels)($result->checks, false))->toBe([]);
});

it('grades against nothing when the agent never called a tool that identifies the call', function (): void {
    $runner = ($this->simulating)(transcript([['role' => 'agent', 'message' => 'We are closed, sorry.']]));

    $result = $runner->run($this->restaurant, ($this->scenario)([
        'expect' => new Expectations(noOrder: true),
    ]));

    expect(($this->labels)($result->checks, false))->toBe([]);
});

it('lists the tools the agent reached for, without repeats', function (): void {
    $runner = ($this->simulating)(transcript([
        ['role' => 'agent', 'tool_calls' => [['tool_name' => 'search_menu', 'params_as_json' => '{}']]],
        ['role' => 'agent', 'tool_calls' => [['tool_name' => 'search_menu'], ['tool_name' => 'quote_order']]],
    ]));

    $result = $runner->run($this->restaurant, ($this->scenario)([
        'expect' => new Expectations(toolsCalled: ['search_menu', 'quote_order'], toolsNotCalled: ['create_order']),
    ]));

    expect(($this->labels)($result->checks, false))->toBe([]);
});

// -----------------------------------------------------------------------
// The log
// -----------------------------------------------------------------------

it('logs the transcript and the summary in a form a person can read', function (): void {
    $runner = ($this->simulating)([
        'simulated_conversation' => [
            ['role' => 'agent', 'message' => 'Ember Grill, is this delivery or collection?'],
            ['role' => 'user', 'message' => 'Delivery please.'],
            ['role' => 'agent', 'tool_calls' => [['tool_name' => 'validate_address', 'params_as_json' => '{"spoken":"3 Hanbury Street"}']]],
        ],
        'analysis' => ['transcript_summary' => 'The caller ordered a burger.'],
    ]);

    $log = $runner->run($this->restaurant, ($this->scenario)())->log;

    expect($log[0])->toContain('agent')
        ->and($log[0])->toContain('Ember Grill')
        ->and($log[1])->toContain('caller')
        ->and($log[2])->toContain('validate_address')
        ->and(implode("\n", $log))->toContain('summary  The caller ordered a burger.');
});
