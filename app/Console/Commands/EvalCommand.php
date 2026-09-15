<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\Evals\EvalScenarioException;
use App\Services\Evals\ReplayRunner;
use App\Services\Evals\Scenario;
use App\Services\Evals\ScenarioFile;
use App\Services\Evals\ScenarioResult;
use App\Services\Evals\SimulationRunner;
use Illuminate\Console\Command;

/**
 * Asks whether the agent still takes orders properly.
 *
 * Two modes, and they answer different questions.
 *
 * `--mode=fake`, the default, replays each scenario's tool calls against this
 * application: real routes, real bearer token, real validation, real menu
 * matching, real pricing, real order state machine. It costs nothing, needs no
 * ElevenLabs account, is deterministic, and is what CI runs. What it cannot
 * tell you is whether the agent would have made those calls.
 *
 * `--mode=live` asks ElevenLabs to run the whole conversation against the
 * provisioned agent with a model playing the caller, and grades both what
 * ended up in the database and — through a judge model — whether the agent
 * behaved. That is the only way to check the things this project is actually
 * built around: that the order is read back before it is committed, that a
 * human is offered, that a card number is refused. It costs money per scenario
 * and takes a minute each, so it is a thing you run before a release.
 *
 * Scenarios are JSON files in `evals/scenarios`. Write more of them; they are
 * the cheapest documentation of what "working" means for a given restaurant.
 */
final class EvalCommand extends Command
{
    protected $signature = 'kitchenline:eval
        {scenario?* : Run only these scenarios, by file name without the .json}
        {--mode= : fake (default, free, what CI runs) or live (real agent, costs money)}
        {--tag=* : Run only scenarios carrying this tag}
        {--path= : Where the scenario files live}
        {--restaurant= : Slug of the restaurant to run against, if this install has more than one}';

    protected $description = 'Run the eval scenarios against the ordering flow, or against the real agent';

    public function handle(ReplayRunner $replay, SimulationRunner $simulation): int
    {
        $restaurant = $this->restaurant();

        if ($restaurant === null) {
            return self::FAILURE;
        }

        $mode = (string) ($this->option('mode') ?? config('restaurantline.evals.mode', 'fake'));

        if (! in_array($mode, ['fake', 'live'], strict: true)) {
            $this->components->error(sprintf('There is no "%s" mode. It is fake or live.', $mode));

            return self::FAILURE;
        }

        $path = (string) ($this->option('path') ?? config('restaurantline.evals.scenarios_path', base_path('evals/scenarios')));

        try {
            $scenarios = $this->filter(ScenarioFile::all($path));
        } catch (EvalScenarioException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($scenarios === []) {
            $this->components->error(sprintf('No scenarios in %s matched.', $path));

            return self::FAILURE;
        }

        $this->preamble($restaurant, $mode, $scenarios);

        $results = [];

        foreach ($scenarios as $scenario) {
            $result = $mode === 'live'
                ? $simulation->run($restaurant, $scenario)
                : $replay->run($restaurant, $scenario);

            $this->result($result);

            $results[] = $result;
        }

        return $this->summary($results);
    }

    // -----------------------------------------------------------------------

    private function restaurant(): ?Restaurant
    {
        $slug = $this->option('restaurant');

        if (is_string($slug) && $slug !== '') {
            $found = Restaurant::query()->where('slug', $slug)->first();

            if ($found === null) {
                $this->components->error(sprintf('No restaurant with the slug "%s".', $slug));
            }

            return $found;
        }

        $restaurant = Restaurant::query()->orderBy('id')->first();

        if ($restaurant === null) {
            $this->components->error('There is no restaurant to run against. Run `php artisan migrate --seed` first.');
        }

        return $restaurant;
    }

    /**
     * @param  list<Scenario>  $scenarios
     * @return list<Scenario>
     */
    private function filter(array $scenarios): array
    {
        /** @var list<string> $names */
        $names = $this->argument('scenario');

        /** @var list<string> $tags */
        $tags = $this->option('tag');

        if ($names !== []) {
            $scenarios = array_filter(
                $scenarios,
                static fn (Scenario $scenario): bool => in_array($scenario->name, $names, strict: true),
            );
        }

        if ($tags !== []) {
            $scenarios = array_filter($scenarios, static function (Scenario $scenario) use ($tags): bool {
                foreach ($tags as $tag) {
                    if ($scenario->hasTag($tag)) {
                        return true;
                    }
                }

                return false;
            });
        }

        return array_values($scenarios);
    }

    /**
     * @param  list<Scenario>  $scenarios
     */
    private function preamble(Restaurant $restaurant, string $mode, array $scenarios): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Restaurant</>', $restaurant->name);
        $this->components->twoColumnDetail('<fg=gray>Mode</>', $mode);
        $this->components->twoColumnDetail('<fg=gray>Scenarios</>', (string) count($scenarios));
        $this->newLine();

        if ($mode !== 'live') {
            return;
        }

        $this->components->warn(
            'Live mode has ElevenLabs run each of these as a real conversation against the provisioned '
            .'agent, which is billed per scenario and calls the tool URLs for real. Orders created here '
            .'are orders in your database.',
        );
    }

    private function result(ScenarioResult $result): void
    {
        if ($result->error !== null) {
            $this->components->twoColumnDetail(
                $result->scenario->title,
                '<fg=yellow;options=bold>ERROR</>',
            );
            $this->line('    <fg=yellow>'.$result->error.'</>');
            $this->newLine();

            return;
        }

        $passed = $result->passed();

        $this->components->twoColumnDetail(
            $result->scenario->title,
            sprintf(
                '%s <fg=gray>%d checks, %.1fs</>',
                $passed ? '<fg=green;options=bold>PASS</>' : '<fg=red;options=bold>FAIL</>',
                count($result->checks),
                $result->seconds,
            ),
        );

        foreach ($result->failures() as $failure) {
            $this->line(sprintf('    <fg=red>✗</> %s <fg=gray>— %s</>', $failure->label, (string) $failure->detail));
        }

        // The conversation itself, on failure or on request. A failing check
        // tells you what was wrong with the outcome; the log is the only thing
        // that tells you where it went wrong.
        if (! $passed || $this->output->isVerbose()) {
            $this->newLine();

            foreach ($result->log as $line) {
                $this->line('    <fg=gray>'.$line.'</>');
            }
        }

        if (! $passed || $this->output->isVerbose()) {
            $this->newLine();
        }
    }

    /**
     * @param  list<ScenarioResult>  $results
     */
    private function summary(array $results): int
    {
        $passed = array_filter($results, static fn (ScenarioResult $result): bool => $result->passed());
        $errored = array_filter($results, static fn (ScenarioResult $result): bool => $result->error !== null);

        $checks = array_sum(array_map(
            static fn (ScenarioResult $result): int => count($result->checks),
            $results,
        ));

        $failedChecks = array_sum(array_map(
            static fn (ScenarioResult $result): int => count($result->failures()),
            $results,
        ));

        $this->newLine();

        if (count($passed) === count($results)) {
            $this->components->info(sprintf(
                '%d scenarios, %d checks, all passing.',
                count($results),
                $checks,
            ));

            return self::SUCCESS;
        }

        $this->components->error(sprintf(
            '%d of %d scenarios failed — %d checks, %d of them failing%s.',
            count($results) - count($passed),
            count($results),
            $checks,
            $failedChecks,
            $errored === [] ? '' : sprintf(', and %d scenarios that could not run at all', count($errored)),
        ));

        if ($errored !== []) {
            $this->components->warn(
                'An ERROR is a problem with the eval rather than with the agent: a tool that no longer '
                .'exists, a placeholder nothing bound, a scenario the chosen mode cannot run.',
            );
        }

        return self::FAILURE;
    }
}
