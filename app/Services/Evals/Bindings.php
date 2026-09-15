<?php

declare(strict_types=1);

namespace App\Services\Evals;

use Illuminate\Support\Arr;

/**
 * The values a scenario cannot know until it is running.
 *
 * An address token is a signed blob. An order number is generated. A
 * conversation id has to be the same across every call in one scenario and
 * different between two runs, or the second run replays the first run's
 * idempotency and creates nothing. None of the three can be written down in a
 * file, so a scenario writes `{{address_token}}` and this fills it in from what
 * came back earlier in the same run.
 *
 * Most captures are automatic, because the two values worth threading appear in
 * the same place every time. A scenario only writes a `capture` block when it
 * wants something unusual — the second address candidate rather than the first,
 * say, which is how you test a caller correcting the agent.
 */
final class Bindings
{
    /** @var array<string, string> */
    private array $values = [];

    public function __construct(string $conversationId)
    {
        $this->values['conversation_id'] = $conversationId;
    }

    /**
     * Placeholder names are matched without regard to case.
     *
     * The alternative is a scenario that writes `{{ORDER_NUMBER}}`, matches the
     * placeholder pattern, finds nothing bound under that spelling and reports
     * that create_order never produced an order number — which is both untrue
     * and a long way from the actual mistake.
     */
    private static function normalise(string $name): string
    {
        return mb_strtolower($name);
    }

    public function set(string $name, string $value): void
    {
        $this->values[self::normalise($name)] = $value;
    }

    public function get(string $name): ?string
    {
        return $this->values[self::normalise($name)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->values;
    }

    /**
     * Fill in every `{{name}}` in a scenario's parameters.
     *
     * A placeholder with nothing bound to it is an error rather than an empty
     * string. Sending `""` as an address token produces a validation failure
     * three calls later, and the scenario that reports it is not the one that
     * caused it.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function fill(array $params, string $where): array
    {
        /** @var array<string, mixed> $filled */
        $filled = $this->walk($params, $where);

        return $filled;
    }

    /**
     * Take from a response whatever a later call is likely to need.
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, string>  $explicit  binding name => dot path into the response
     */
    public function capture(array $response, array $explicit = []): void
    {
        foreach (self::AUTOMATIC as $name => $path) {
            $value = Arr::get($response, $path);

            if (is_string($value) && $value !== '') {
                $this->set($name, $value);
            }
        }

        foreach ($explicit as $name => $path) {
            $value = Arr::get($response, $path);

            if (is_scalar($value)) {
                $this->set($name, (string) $value);
            }
        }
    }

    /**
     * Where the two threaded values live in the responses that produce them.
     *
     * The first candidate rather than the best-scoring one because the agent
     * is told to read the first one back, and an eval that quietly picks a
     * different candidate than the agent would is testing a conversation
     * nobody is having.
     */
    private const AUTOMATIC = [
        'address_token' => 'candidates.0.address_token',
        'order_number' => 'order_number',
    ];

    private function walk(mixed $value, string $where): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->walk($item, $where), $value);
        }

        if (! is_string($value)) {
            return $value;
        }

        // A whole-string placeholder keeps its type, so `{{quantity}}` could
        // one day bind a number. An embedded one is interpolated as text.
        if (preg_match('/^\{\{\s*([a-z0-9_]+)\s*\}\}$/i', $value, $matches) === 1) {
            return $this->must($matches[1], $where);
        }

        return preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            fn (array $matches): string => $this->must($matches[1], $where),
            $value,
        ) ?? $value;
    }

    private function must(string $name, string $where): string
    {
        $value = $this->values[self::normalise($name)] ?? null;

        if ($value === null) {
            throw EvalScenarioException::at($where, sprintf(
                'nothing has bound {{%s}} yet. It is set by an earlier call in the same scenario — '
                .'an address token by validate_address, an order number by create_order. Bound so far: %s.',
                $name,
                $this->values === [] ? 'nothing' : implode(', ', array_keys($this->values)),
            ));
        }

        return $value;
    }
}
