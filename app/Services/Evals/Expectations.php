<?php

declare(strict_types=1);

namespace App\Services\Evals;

/**
 * What the call was supposed to leave behind.
 *
 * Every field is optional, and a scenario states only what it is about. A
 * scenario that exists to prove the agent refuses a card number has nothing to
 * say about the delivery fee, and made to say something it would break for
 * reasons that are not the point.
 *
 * Prices here are in major units — `18.40`, six pounds fifty — for the same
 * reason the menu importer takes them that way (#0041): one rule with no
 * exceptions beats a clever one, and a scenario file is read far more often
 * than it is written.
 */
final readonly class Expectations
{
    /**
     * @param  list<string>  $toolsCalled
     * @param  list<string>  $toolsNotCalled
     * @param  array<string, mixed>|null  $order
     */
    public function __construct(
        public array $toolsCalled = [],
        public array $toolsNotCalled = [],
        public ?array $order = null,
        public ?bool $noOrder = null,
        public ?bool $escalated = null,
        public ?bool $flaggedForReview = null,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw, string $where): self
    {
        $known = ['tools_called', 'tools_not_called', 'order', 'no_order', 'escalated', 'flagged_for_review'];
        $unknown = array_diff(array_keys($raw), $known);

        // A misspelt expectation is an assertion that silently never runs,
        // which is worse than no assertion at all: the scenario passes and
        // reports that it checked something it did not.
        if ($unknown !== []) {
            throw EvalScenarioException::at($where, sprintf(
                'does not understand the expectation "%s". Try one of: %s.',
                (string) reset($unknown),
                implode(', ', $known),
            ));
        }

        return new self(
            toolsCalled: self::strings($raw['tools_called'] ?? [], $where, 'tools_called'),
            toolsNotCalled: self::strings($raw['tools_not_called'] ?? [], $where, 'tools_not_called'),
            order: is_array($raw['order'] ?? null) ? $raw['order'] : null,
            noOrder: is_bool($raw['no_order'] ?? null) ? $raw['no_order'] : null,
            escalated: is_bool($raw['escalated'] ?? null) ? $raw['escalated'] : null,
            flaggedForReview: is_bool($raw['flagged_for_review'] ?? null) ? $raw['flagged_for_review'] : null,
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, string $where, string $key): array
    {
        if ($value === []) {
            return [];
        }

        if (! is_array($value)) {
            throw EvalScenarioException::at($where, sprintf('"%s" must be a list of tool names.', $key));
        }

        return array_values(array_map(strval(...), $value));
    }
}
