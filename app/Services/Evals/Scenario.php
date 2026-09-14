<?php

declare(strict_types=1);

namespace App\Services\Evals;

/**
 * One call, written down.
 *
 * A scenario says three things: what the caller wants, what a competent agent
 * would do about it, and what the restaurant should be left with. The first is
 * prose for the model that plays the caller in live mode; the second is a list
 * of tool calls that fake mode replays; the third is the same set of assertions
 * either way.
 *
 * Both middles are optional in principle and neither is in practice — a file
 * with no `calls` cannot run in fake mode and a file with no `caller` cannot
 * run live — so the reader insists on the one the chosen mode needs and says
 * which is missing rather than running an empty scenario and calling it a pass.
 *
 * `at` is when the call happens, in the restaurant's own timezone. Almost no
 * scenario sets it: fake mode picks a moment the kitchen is open, because
 * otherwise every order scenario fails overnight and the harness becomes
 * something you only run in the afternoon. The ones that do set it are the
 * ones about time — ringing at three in the morning, ringing ten minutes
 * before the kitchen shuts.
 *
 * `criteria` is the fourth thing, and only live mode can answer it. Whether the
 * agent read the order back before committing it, whether it offered a human,
 * whether it refused a card number when one was pushed at it — none of those
 * leave a row in a table, and all of them are the design constraints this
 * project exists to hold. They are graded by ElevenLabs' own judge model
 * against a prose goal, which is the only tool that fits the question.
 */
final readonly class Scenario
{
    /**
     * @param  list<ScenarioCall>  $calls
     * @param  list<array{id: string, name: string, conversation_goal_prompt: string}>  $criteria
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $name,
        public string $title,
        public ?string $description,
        public ?string $caller,
        public array $calls,
        public Expectations $expect,
        public array $criteria = [],
        public array $tags = [],
        public ?string $at = null,
        public ?string $path = null,
    ) {}

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags, strict: true);
    }
}
