<?php

declare(strict_types=1);

namespace App\Services\Evals;

use Illuminate\Support\Str;
use JsonException;

/**
 * Reads `evals/scenarios/*.json` into scenarios.
 *
 * JSON rather than YAML because there is no YAML parser in the container and
 * adding one to read four short files is a dependency a forker inherits
 * forever. JSON with generous validation errors is a fair trade; a scenario is
 * a fixture, not a configuration language.
 *
 * Everything this reads is a file in the repository rather than anything a user
 * sent, so the strictness here is not a security boundary — it is the
 * difference between "scenario 3 line 12 has no `tool`" and a null dereference
 * inside the runner an hour later.
 */
final class ScenarioFile
{
    /**
     * Every scenario in a directory, in filename order.
     *
     * @return list<Scenario>
     */
    public static function all(string $directory): array
    {
        if (! is_dir($directory)) {
            throw EvalScenarioException::at($directory, 'there is no such directory.');
        }

        $paths = glob(rtrim($directory, '/').'/*.json') ?: [];

        sort($paths);

        return array_map(self::read(...), $paths);
    }

    public static function read(string $path): Scenario
    {
        $contents = is_file($path) && is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw EvalScenarioException::at($path, 'no such file, or it cannot be read.');
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw EvalScenarioException::at($path, 'this is not valid JSON — '.$exception->getMessage());
        }

        if (! is_array($decoded)) {
            throw EvalScenarioException::at($path, 'expected an object at the top level.');
        }

        $name = Str::of(basename($path, '.json'))->slug()->value();
        $where = basename($path);

        return new Scenario(
            name: $name,
            title: self::string($decoded, 'title', $where) ?? Str::headline($name),
            description: self::string($decoded, 'description', $where),
            caller: self::string($decoded, 'caller', $where),
            calls: self::calls($decoded['calls'] ?? [], $where),
            expect: Expectations::fromArray(
                is_array($decoded['expect'] ?? null) ? $decoded['expect'] : [],
                $where,
            ),
            criteria: self::criteria($decoded['criteria'] ?? [], $where),
            tags: self::tags($decoded['tags'] ?? [], $where),
            at: self::string($decoded, 'at', $where),
            path: $path,
        );
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private static function string(array $decoded, string $key, string $where): ?string
    {
        $value = $decoded[$key] ?? null;

        if ($value === null) {
            return null;
        }

        // A list of lines, joined. A caller's brief is a paragraph, and a
        // paragraph in a JSON string is one unreadable line; letting it be an
        // array is the whole reason these files are pleasant to edit.
        if (is_array($value)) {
            return implode("\n", array_map(strval(...), $value));
        }

        if (! is_string($value)) {
            throw EvalScenarioException::at($where, sprintf('"%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * @return list<ScenarioCall>
     */
    private static function calls(mixed $raw, string $where): array
    {
        if (! is_array($raw)) {
            throw EvalScenarioException::at($where, '"calls" must be a list of tool calls.');
        }

        $calls = [];

        foreach (array_values($raw) as $index => $call) {
            $at = sprintf('%s call %d', $where, $index + 1);

            if (! is_array($call)) {
                throw EvalScenarioException::at($at, 'expected an object.');
            }

            $tool = $call['tool'] ?? null;

            if (! is_string($tool) || $tool === '') {
                throw EvalScenarioException::at($at, 'has no "tool". Name the tool the agent would call.');
            }

            $calls[] = new ScenarioCall(
                tool: $tool,
                params: is_array($call['params'] ?? null) ? $call['params'] : [],
                expect: is_array($call['expect'] ?? null) ? $call['expect'] : [],
                capture: self::capture($call['capture'] ?? [], $at),
                note: self::string($call, 'note', $at),
            );
        }

        return $calls;
    }

    /**
     * @return array<string, string>
     */
    private static function capture(mixed $raw, string $where): array
    {
        if ($raw === []) {
            return [];
        }

        if (! is_array($raw)) {
            throw EvalScenarioException::at($where, '"capture" must be an object of name => path.');
        }

        $capture = [];

        foreach ($raw as $name => $path) {
            $capture[(string) $name] = (string) (is_scalar($path) ? $path : '');
        }

        return $capture;
    }

    /**
     * The prose goals a judge model grades the transcript against.
     *
     * Shaped as ElevenLabs' `PromptEvaluationCriteria` on the way out, so the
     * file writes the two fields that matter — a name and a goal — and the id
     * is derived from the name rather than being one more thing to keep unique
     * by hand.
     *
     * @return list<array{id: string, name: string, conversation_goal_prompt: string}>
     */
    private static function criteria(mixed $raw, string $where): array
    {
        if ($raw === []) {
            return [];
        }

        if (! is_array($raw)) {
            throw EvalScenarioException::at($where, '"criteria" must be a list of goals.');
        }

        $criteria = [];

        foreach (array_values($raw) as $index => $criterion) {
            $at = sprintf('%s criterion %d', $where, $index + 1);

            if (! is_array($criterion)) {
                throw EvalScenarioException::at($at, 'expected an object with "name" and "goal".');
            }

            $name = self::string($criterion, 'name', $at);
            $goal = self::string($criterion, 'goal', $at);

            if ($name === null || $goal === null) {
                throw EvalScenarioException::at($at, 'needs both a "name" and a "goal". The goal is read by '
                    .'a judge model, so write it as an instruction: "the agent read the whole order back '
                    .'before it said the order was placed".');
            }

            $criteria[] = [
                'id' => Str::of($name)->slug('_')->value(),
                'name' => $name,
                'conversation_goal_prompt' => $goal,
            ];
        }

        return $criteria;
    }

    /**
     * @return list<string>
     */
    private static function tags(mixed $raw, string $where): array
    {
        if ($raw === []) {
            return [];
        }

        if (! is_array($raw)) {
            throw EvalScenarioException::at($where, '"tags" must be a list of strings.');
        }

        return array_values(array_map(strval(...), $raw));
    }
}
