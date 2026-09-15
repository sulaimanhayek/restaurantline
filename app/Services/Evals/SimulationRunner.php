<?php

declare(strict_types=1);

namespace App\Services\Evals;

use App\Models\Order;
use App\Models\Restaurant;
use App\Services\ElevenLabs\ElevenLabsClient;
use App\Services\ElevenLabs\ElevenLabsException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Throwable;

/**
 * Live mode: a real agent, a real model playing the caller, real tool calls.
 *
 * ElevenLabs runs the whole conversation server-side and hands back the
 * transcript and its own analysis. There is no audio anywhere in this — no
 * stream, no turn-taking, no websocket — which is what keeps a live eval
 * inside this project's remit. We provision an agent and then ask their
 * platform to have a conversation with it.
 *
 * It grades two different kinds of thing, because there are two:
 *
 *  - What the restaurant was left with. An order, its lines, its total, its
 *    state. Read from the database by `OutcomeGrader`, exactly as in fake mode,
 *    so a live failure and a fake failure read the same.
 *  - What the agent did with the caller. Whether it read the order back before
 *    committing it, whether it offered a human when it was stuck, whether it
 *    refused a card number. Graded by a judge model against the scenario's
 *    prose criteria, because these leave no trace in any table and are
 *    precisely the constraints this application is built around.
 *
 * Slow and metered. `kitchenline:eval` runs fake mode unless told otherwise.
 */
final class SimulationRunner
{
    public function __construct(
        private readonly ElevenLabsClient $client,
        private readonly OutcomeGrader $grader,
    ) {}

    public function run(Restaurant $restaurant, Scenario $scenario): ScenarioResult
    {
        $started = microtime(true);

        if ($scenario->caller === null) {
            return ScenarioResult::failedToRun(
                $scenario,
                'has no "caller", so there is nobody to play the customer. Add one, or run it in fake mode.',
            );
        }

        $agentId = $restaurant->elevenlabs_agent_id;

        if ($agentId === null) {
            return ScenarioResult::failedToRun(
                $scenario,
                sprintf(
                    '%s has no ElevenLabs agent yet. Run `php artisan kitchenline:provision` first — a live '
                    .'eval talks to the agent that provisioning creates.',
                    $restaurant->name,
                ),
            );
        }

        try {
            $simulation = $this->client->simulateConversation(
                agentId: $agentId,
                simulationSpecification: $this->specification($scenario),
                evaluationCriteria: $scenario->criteria,
                turnLimit: (int) config('restaurantline.evals.turn_limit', 20),
            );
        } catch (ElevenLabsException $exception) {
            return ScenarioResult::failedToRun($scenario, $exception->getMessage(), microtime(true) - $started);
        } catch (Throwable $exception) {
            return ScenarioResult::failedToRun(
                $scenario,
                $exception::class.': '.$exception->getMessage(),
                microtime(true) - $started,
            );
        }

        $transcript = $simulation['simulated_conversation'];
        $analysis = $simulation['analysis'];

        $checks = $this->gradeCriteria($scenario, $analysis);

        $conversationId = $this->conversationId($restaurant, $transcript);

        if ($conversationId === null) {
            // Nothing this agent did touched an endpoint that identifies the
            // call, so there is nothing in the database to look for. That is a
            // real result for a scenario expecting no order, and a failure for
            // one expecting anything else — which `grade` will say, with a
            // conversation id that matches nothing.
            $conversationId = 'eval-no-tool-call-'.Str::lower(Str::random(8));
        }

        $checks = array_merge($checks, $this->grader->grade(
            $restaurant,
            $conversationId,
            $scenario->expect,
            $this->toolsCalled($transcript),
        ));

        return new ScenarioResult($scenario, $checks, $this->log($transcript, $analysis), microtime(true) - $started);
    }

    // -----------------------------------------------------------------------

    /**
     * The model that plays the caller, and what it wants.
     *
     * The prompt is the scenario's `caller` prose with a short preamble, and
     * the preamble is doing real work: a model told only "you want two burgers"
     * will happily answer questions it was never asked, volunteer its postcode
     * before anyone requests it, and generally behave like a cooperative test
     * fixture rather than a person on a telephone. The instruction to answer
     * what is asked and nothing more is what makes the eval about the agent.
     *
     * @return array<string, mixed>
     */
    private function specification(Scenario $scenario): array
    {
        return [
            'simulated_user_config' => [
                'prompt' => [
                    'prompt' => $this->callerPrompt($scenario),
                    'llm' => (string) config('restaurantline.evals.caller_model', 'claude-sonnet-5'),
                ],
            ],
        ];
    }

    private function callerPrompt(Scenario $scenario): string
    {
        return implode("\n\n", [
            'You are a member of the public ringing a restaurant to place an order. You are talking to '
            .'whoever answered the phone. Speak the way someone speaks on the telephone: short sentences, '
            .'one thing at a time.',

            'Answer what you are asked and nothing more. Do not volunteer your address, your phone number '
            .'or your payment preference until somebody asks for them. Do not narrate, do not describe '
            .'what you are doing, and never mention that this is a test.',

            'End the call once you have what you rang for, or once it is clear you are not going to get it.',

            "Here is what you want:\n".(string) $scenario->caller,
        ]);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return list<Check>
     */
    private function gradeCriteria(Scenario $scenario, array $analysis): array
    {
        $results = $analysis['evaluation_criteria_results'] ?? [];
        $results = is_array($results) ? $results : [];

        $checks = [];

        foreach ($scenario->criteria as $criterion) {
            $result = $results[$criterion['id']] ?? null;

            if (! is_array($result)) {
                $checks[] = Check::fail($criterion['name'], 'the judge returned no verdict on this one.');

                continue;
            }

            $verdict = (string) ($result['result'] ?? 'unknown');

            // `unknown` is a failure rather than a pass. A judge that could not
            // tell whether the agent read the order back is not evidence that
            // it did.
            $checks[] = $verdict === 'success'
                ? Check::pass($criterion['name'])
                : Check::fail($criterion['name'], sprintf(
                    '%s — %s',
                    $verdict,
                    (string) ($result['rationale'] ?? 'no rationale given'),
                ));
        }

        return $checks;
    }

    /**
     * Which tools the agent reached for, in order, without repeats.
     *
     * @param  list<array<string, mixed>>  $transcript
     * @return list<string>
     */
    private function toolsCalled(array $transcript): array
    {
        $called = [];

        foreach ($transcript as $turn) {
            foreach ($this->toolCalls($turn) as $call) {
                $name = $call['tool_name'] ?? null;

                if (is_string($name) && ! in_array($name, $called, strict: true)) {
                    $called[] = $name;
                }
            }
        }

        return $called;
    }

    /**
     * Work out which conversation, in our database, this simulation was.
     *
     * ElevenLabs assigns the id and does not return it, so it has to be
     * recovered from what the agent did with it. Two endpoints give it away:
     * escalation answers with the conversation directly, and an order carries
     * it on the row. Both are more trustworthy than anything we could guess,
     * because both are the id the application actually stored.
     *
     * @param  list<array<string, mixed>>  $transcript
     */
    private function conversationId(Restaurant $restaurant, array $transcript): ?string
    {
        $orderNumber = null;

        foreach ($transcript as $turn) {
            foreach ($this->toolResults($turn) as $result) {
                $body = $this->decode($result['result_value'] ?? null);

                $conversation = Arr::get($body, 'conversation');

                if (is_string($conversation) && $conversation !== '') {
                    return $conversation;
                }

                $number = Arr::get($body, 'order_number');

                if (is_string($number) && $number !== '') {
                    $orderNumber = $number;
                }
            }
        }

        if ($orderNumber === null) {
            return null;
        }

        return Order::query()
            ->where('restaurant_id', $restaurant->id)
            ->where('order_number', $orderNumber)
            ->value('elevenlabs_conversation_id');
    }

    /**
     * @param  list<array<string, mixed>>  $transcript
     * @param  array<string, mixed>  $analysis
     * @return list<string>
     */
    private function log(array $transcript, array $analysis): array
    {
        $lines = [];

        foreach ($transcript as $turn) {
            $role = (string) ($turn['role'] ?? '?');
            $message = $turn['message'] ?? null;

            if (is_string($message) && trim($message) !== '') {
                $lines[] = sprintf('%-6s %s', $role === 'user' ? 'caller' : 'agent', $message);
            }

            foreach ($this->toolCalls($turn) as $call) {
                $lines[] = sprintf(
                    '%-6s → %s %s',
                    '',
                    (string) ($call['tool_name'] ?? '?'),
                    Str::limit((string) ($call['params_as_json'] ?? ''), 90),
                );
            }
        }

        $summary = $analysis['transcript_summary'] ?? null;

        if (is_string($summary) && $summary !== '') {
            $lines[] = '';
            $lines[] = 'summary  '.$summary;
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $turn
     * @return list<array<string, mixed>>
     */
    private function toolCalls(array $turn): array
    {
        $calls = $turn['tool_calls'] ?? [];

        return is_array($calls) ? array_values(array_filter($calls, is_array(...))) : [];
    }

    /**
     * @param  array<string, mixed>  $turn
     * @return list<array<string, mixed>>
     */
    private function toolResults(array $turn): array
    {
        $results = $turn['tool_results'] ?? [];

        return is_array($results) ? array_values(array_filter($results, is_array(...))) : [];
    }

    /**
     * A tool result comes back as a string holding our JSON body.
     *
     * @return array<string, mixed>
     */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
