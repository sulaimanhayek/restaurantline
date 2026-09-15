<?php

declare(strict_types=1);

namespace App\Services\Evals;

use App\Models\Restaurant;
use App\Services\ElevenLabs\ToolDefinitions;
use App\Services\Hours\OpeningHoursService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fake mode: the scenario's tool calls, played against this application.
 *
 * What this is not is a recording being read back. A harness that replays a
 * stored transcript and compares it to itself tests the comparison; this sends
 * the calls, through the real routes, the real bearer-token middleware, the
 * real form requests, the real pricing and matching services, and asks the
 * database afterwards what happened. Routing, authentication, validation,
 * menu matching, pricing and the order state machine are all live. The only
 * thing faked is the part that costs money and cannot be deterministic: the
 * language model deciding what to say.
 *
 * That is the right seam. The model is ElevenLabs' problem and changes when
 * they change it; everything on this side of the webhook is ours and regresses
 * when we touch it.
 *
 * The URLs come from `ToolDefinitions` rather than from `route()`, so a
 * scenario hits the exact URL provisioning tells ElevenLabs to hit. Rename a
 * route and forget to re-provision, and these fail — which is the point.
 *
 * It moves the clock. `create_order` refuses to take an order while the kitchen
 * is shut, correctly, which would mean this harness only worked in the
 * afternoons; so unless a scenario names its own moment, each one runs shortly
 * after the restaurant next opens. That is a liberty a replay is entitled to
 * take and a live run is not, and it is what makes two runs of the same
 * scenario comparable at all.
 */
final class ReplayRunner
{
    public function __construct(
        private readonly Kernel $kernel,
        private readonly OutcomeGrader $grader,
        private readonly OpeningHoursService $hours,
    ) {}

    public function run(Restaurant $restaurant, Scenario $scenario): ScenarioResult
    {
        $started = microtime(true);

        if ($scenario->calls === []) {
            return ScenarioResult::failedToRun(
                $scenario,
                'has no "calls", so there is nothing to replay. Add some, or run it with --mode=live.',
            );
        }

        $conversationId = 'eval_'.$scenario->name.'_'.Str::lower(Str::random(12));
        $bindings = new Bindings($conversationId);
        $tools = (new ToolDefinitions($restaurant, 'eval'))->all();

        $checks = [];
        $log = [];
        $called = [];

        try {
            $at = $this->clock($restaurant, $scenario);
        } catch (EvalScenarioException $exception) {
            return ScenarioResult::failedToRun($scenario, $exception->getMessage(), microtime(true) - $started);
        }

        Carbon::setTestNow($at);

        $log[] = sprintf('clock              %s', $at->timezone($restaurant->timezone)->format('D j M Y, H:i T'));

        try {
            foreach ($scenario->calls as $index => $call) {
                $where = sprintf('%s call %d (%s)', $scenario->name, $index + 1, $call->tool);

                if (! isset($tools[$call->tool])) {
                    return ScenarioResult::failedToRun($scenario, EvalScenarioException::at(
                        $where,
                        sprintf('there is no such tool. The agent has: %s.', implode(', ', array_keys($tools))),
                    )->getMessage(), microtime(true) - $started);
                }

                $params = $bindings->fill($call->params, $where);
                $params['conversation_id'] ??= $conversationId;

                [$status, $body] = $this->send($tools[$call->tool], $params);

                $called[] = $call->tool;
                $log[] = $this->line($call, $status, $body);
                $bindings->capture($body, $call->capture);

                $checks = array_merge($checks, $this->gradeResponse($call, $status, $body));
            }
        } catch (EvalScenarioException $exception) {
            return ScenarioResult::failedToRun($scenario, $exception->getMessage(), microtime(true) - $started);
        } catch (Throwable $exception) {
            return ScenarioResult::failedToRun(
                $scenario,
                $exception::class.': '.$exception->getMessage(),
                microtime(true) - $started,
            );
        } finally {
            // Whatever happened, the next scenario gets its own moment and
            // anything after this command gets the real one back.
            Carbon::setTestNow();
        }

        $checks = array_merge(
            $checks,
            $this->grader->grade($restaurant, $conversationId, $scenario->expect, array_values(array_unique($called))),
        );

        return new ScenarioResult($scenario, $checks, $log, microtime(true) - $started);
    }

    /**
     * When this call happens.
     *
     * A scenario that says nothing gets a moment the kitchen is open: now, if
     * it is open now, and otherwise half an hour into the next service. Half an
     * hour rather than on the dot because a window that opens at five has a
     * kitchen that is open at half past, and because an order placed in the
     * first minute of service is a different edge case from the one most
     * scenarios are about.
     */
    private function clock(Restaurant $restaurant, Scenario $scenario): CarbonImmutable
    {
        if ($scenario->at !== null) {
            try {
                return CarbonImmutable::parse($scenario->at, $restaurant->timezone);
            } catch (Throwable $exception) {
                throw EvalScenarioException::at(
                    $scenario->name,
                    sprintf('cannot read "at": %s is not a date and time.', Check::render($scenario->at)),
                );
            }
        }

        $now = CarbonImmutable::now($restaurant->timezone);

        if ($this->hours->isOpenAt($restaurant, $now)) {
            return $now;
        }

        $next = $this->hours->nextOpening($restaurant, $now);

        if ($next === null) {
            throw EvalScenarioException::at(
                $scenario->name,
                'this restaurant has no opening hours at all, so there is no moment at which it could '
                .'take an order. Seed some, or give the scenario an explicit "at".',
            );
        }

        return $next->opensAt->addMinutes(30);
    }

    // -----------------------------------------------------------------------

    /**
     * One tool call, dispatched through the HTTP kernel.
     *
     * Through the kernel rather than over the network, because an eval that
     * needs a running web server is an eval that does not run in CI, and the
     * middleware stack — bearer token, rate limiter, route model binding,
     * the exception renderer that keeps stack traces off these routes — is
     * identical either way.
     *
     * @param  array<string, mixed>  $tool
     * @param  array<string, mixed>  $params
     * @return array{int, array<string, mixed>}
     */
    private function send(array $tool, array $params): array
    {
        /** @var array<string, mixed> $schema */
        $schema = $tool['api_schema'];

        $method = (string) $schema['method'];
        [$url, $params] = $this->fillPath((string) $schema['url'], $params);

        $request = $method === 'GET'
            ? Request::create($url, 'GET', $params)
            : Request::create($url, $method, content: (string) json_encode($params), server: [
                'CONTENT_TYPE' => 'application/json',
            ]);

        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Authorization', 'Bearer '.config('restaurantline.agent.token'));

        $response = $this->kernel->handle($request);

        $decoded = json_decode((string) $response->getContent(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }

    /**
     * Move any `{param}` in the URL out of the body and into the path.
     *
     * Exactly what ElevenLabs does with `path_params_schema`, which is why the
     * scenario writes `"order": "{{order_number}}"` alongside the other
     * parameters and does not have to know it ends up in the URL.
     *
     * @param  array<string, mixed>  $params
     * @return array{string, array<string, mixed>}
     */
    private function fillPath(string $url, array $params): array
    {
        preg_match_all('/\{([a-z_]+)\}/i', $url, $matches);

        foreach ($matches[1] as $name) {
            $value = $params[$name] ?? '';

            $url = str_replace('{'.$name.'}', rawurlencode((string) (is_scalar($value) ? $value : '')), $url);

            unset($params[$name]);
        }

        return [$url, $params];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return list<Check>
     */
    private function gradeResponse(ScenarioCall $call, int $status, array $body): array
    {
        $checks = [];

        foreach ($call->expect as $key => $expected) {
            $label = sprintf('%s → %s', $call->tool, $key);

            $checks[] = match ($key) {
                'status' => Check::equals($label, (int) $expected, $status),
                'ok' => Check::equals($label, (bool) $expected, (bool) ($body['ok'] ?? false)),
                'error' => Check::equals($label, $expected, Arr::get($body, 'error.code')),
                'say_contains' => str_contains(
                    mb_strtolower((string) (Arr::get($body, 'say') ?? Arr::get($body, 'error.say') ?? '')),
                    mb_strtolower((string) $expected),
                )
                    ? Check::pass($label)
                    : Check::fail($label, sprintf(
                        'the agent was given "%s", which does not mention %s',
                        (string) (Arr::get($body, 'say') ?? Arr::get($body, 'error.say') ?? ''),
                        Check::render($expected),
                    )),
                default => Check::equals($label, $expected, Arr::get($body, $key)),
            };
        }

        return $checks;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function line(ScenarioCall $call, int $status, array $body): string
    {
        $ok = ($body['ok'] ?? null) === true;

        $outcome = match (true) {
            $ok => (string) ($body['say'] ?? 'ok'),
            is_string(Arr::get($body, 'error.code')) => sprintf(
                '%s — %s',
                (string) Arr::get($body, 'error.code'),
                (string) Arr::get($body, 'error.say', ''),
            ),
            default => 'HTTP '.$status,
        };

        return sprintf('%-18s %s %s', $call->tool, $status, Str::limit($outcome, 110));
    }
}
