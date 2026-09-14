<?php

declare(strict_types=1);

namespace App\Services\Menu\Import;

/**
 * What an import did, or would have done.
 *
 * The same object comes back from a dry run and a real one, which is the point:
 * the thing you read before deciding is the thing you get afterwards.
 */
final class MenuImportReport
{
    /** @var array<string, list<string>> */
    private array $entries = [];

    /** @var array<string, list<string>> */
    private array $touched = [];

    public function record(string $kind, string $action, string $name, string $slug): void
    {
        $this->entries[$kind.'.'.$action][] = $name;
        $this->touched[$kind][] = $slug;
    }

    /**
     * @return list<string>
     */
    public function names(string $kind, string $action): array
    {
        return $this->entries[$kind.'.'.$action] ?? [];
    }

    public function count(string $kind, string $action): int
    {
        return count($this->names($kind, $action));
    }

    /**
     * Every slug of this kind the file mentioned — which is to say, everything
     * `--prune` must leave alone.
     *
     * @return list<string>
     */
    public function touched(string $kind): array
    {
        return array_values(array_unique($this->touched[$kind] ?? []));
    }

    public function changedAnything(): bool
    {
        foreach ($this->entries as $key => $names) {
            if (! str_ends_with($key, '.unchanged') && $names !== []) {
                return true;
            }
        }

        return false;
    }
}
