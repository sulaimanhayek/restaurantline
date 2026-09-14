<?php

declare(strict_types=1);

namespace App\Services\Evals;

/**
 * One tool call in a replayed conversation.
 *
 * `params` are what the agent sent, verbatim, apart from the placeholders —
 * `{{address_token}}` and `{{order_number}}` stand in for values only the
 * previous response knows. See `Bindings`.
 *
 * `expect` is optional, and usually absent. Most calls in a scenario are there
 * to get to the interesting one, and asserting on every response would make the
 * files unreadable and the failures noisy. The two that always matter —
 * `ok`, and the error code when it is false — are cheap enough to state
 * wherever they are the point of the call.
 *
 * `capture` is rarer still. Bindings takes the first address candidate and the
 * order number without being asked, which covers every scenario where the agent
 * gets it right the first time; `capture` is for the ones where it does not.
 */
final readonly class ScenarioCall
{
    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, mixed>  $expect
     * @param  array<string, string>  $capture  binding name => dot path into the response
     */
    public function __construct(
        public string $tool,
        public array $params,
        public array $expect = [],
        public array $capture = [],
        public ?string $note = null,
    ) {}
}
