<?php

declare(strict_types=1);

namespace App\Services\Menu\Import;

use JsonException;

/**
 * Reads a menu file into the one shape the importer understands.
 *
 * Two formats, because there are two situations. JSON is the full one and can
 * express everything the schema can — categories, items, modifier groups, the
 * lot — and is what you write once you have decided this restaurant is staying.
 * CSV is what actually arrives in your inbox: one row per dish, exported from
 * whatever the owner uses, and the fastest route from an email attachment to a
 * working agent.
 *
 * CSV cannot describe modifier groups. A group is a set with selection rules
 * and its own prices, and every way of flattening that into a row is worse than
 * writing the JSON. A CSV can still *reference* groups by slug, so the usual
 * path — import the items from the spreadsheet, add the sizes in JSON or in the
 * dashboard — works without a second import format for modifiers.
 *
 * **Prices are always in major units.** `6.50` is six pounds fifty, and so is
 * `"£6.50"`, and `6` is six pounds. Never pence. One rule with no exceptions
 * beats a clever one, and `--dry-run` prints every price formatted so a
 * misread costs a glance rather than an evening.
 */
final class MenuFile
{
    /**
     * @return array{categories: list<array<string, mixed>>, modifier_groups: list<array<string, mixed>>}
     */
    public static function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw MenuImportException::at($path, 'no such file, or it cannot be read.');
        }

        $contents = file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            throw MenuImportException::at($path, 'the file is empty.');
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'json' => self::fromJson($path, $contents),
            'csv' => self::fromCsv($path, $contents),
            default => throw MenuImportException::at($path, 'expected a .json or .csv file.'),
        };
    }

    /**
     * @return array{categories: list<array<string, mixed>>, modifier_groups: list<array<string, mixed>>}
     */
    private static function fromJson(string $path, string $contents): array
    {
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw MenuImportException::at($path, 'this is not valid JSON — '.$exception->getMessage());
        }

        if (! is_array($decoded)) {
            throw MenuImportException::at($path, 'expected an object at the top level.');
        }

        $categories = $decoded['categories'] ?? null;
        $groups = $decoded['modifier_groups'] ?? [];

        if (! is_array($categories) || $categories === []) {
            throw MenuImportException::at($path, 'there is no "categories" array, or it is empty. '
                .'Every dish belongs to a section of the menu, so that is where the file starts.');
        }

        if (! is_array($groups)) {
            throw MenuImportException::at($path, '"modifier_groups" must be an array.');
        }

        return [
            'categories' => array_values(array_filter($categories, is_array(...))),
            'modifier_groups' => array_values(array_filter($groups, is_array(...))),
        ];
    }

    /**
     * One row per dish, with the category repeated down the column.
     *
     * Header names are matched loosely — case, spaces and underscores are all
     * ignored — because "Item Name" and "item_name" are the same column and
     * making somebody rename a header before their first import is a poor
     * welcome.
     *
     * @return array{categories: list<array<string, mixed>>, modifier_groups: list<array<string, mixed>>}
     */
    private static function fromCsv(string $path, string $contents): array
    {
        $rows = array_map(str_getcsv(...), preg_split('/\R/u', trim($contents)) ?: []);

        $header = array_shift($rows);

        if ($header === null) {
            throw MenuImportException::at($path, 'the file has no header row.');
        }

        $columns = array_map(
            static fn (?string $name): string => preg_replace('/[^a-z0-9]/', '', strtolower((string) $name)) ?? '',
            $header,
        );

        foreach (['category', 'name', 'price'] as $required) {
            if (! in_array($required, $columns, strict: true)) {
                throw MenuImportException::at($path, sprintf(
                    'there is no "%s" column. The header needs at least: category, name, price.',
                    $required,
                ));
            }
        }

        /** @var array<string, array<string, mixed>> $categories */
        $categories = [];

        foreach ($rows as $index => $row) {
            // A trailing newline, or a blank line somebody left in the middle.
            if ($row === [null] || implode('', array_map(strval(...), $row)) === '') {
                continue;
            }

            $line = $index + 2;
            $cells = self::combine($columns, $row);

            $category = trim((string) ($cells['category'] ?? ''));
            $name = trim((string) ($cells['name'] ?? ''));

            if ($category === '' || $name === '') {
                throw MenuImportException::at(
                    sprintf('%s line %d', $path, $line),
                    'every row needs a category and a name.',
                );
            }

            $categories[$category] ??= ['name' => $category, 'items' => []];
            $categories[$category]['items'][] = array_filter([
                'name' => $name,
                'description' => self::text($cells, 'description'),
                'price' => self::text($cells, 'price'),
                'sku' => self::text($cells, 'sku'),
                'prep_minutes' => self::text($cells, 'prepminutes'),
                'is_available' => self::text($cells, 'available'),
                'aliases' => self::list($cells, 'aliases'),
                'modifier_groups' => self::list($cells, 'modifiergroups'),
            ], static fn (mixed $value): bool => $value !== null);
        }

        if ($categories === []) {
            throw MenuImportException::at($path, 'the file has a header but no rows.');
        }

        return ['categories' => array_values($categories), 'modifier_groups' => []];
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string|null>  $row
     * @return array<string, string>
     */
    private static function combine(array $columns, array $row): array
    {
        $cells = [];

        foreach ($columns as $position => $column) {
            if ($column !== '') {
                $cells[$column] = trim((string) ($row[$position] ?? ''));
            }
        }

        return $cells;
    }

    /**
     * @param  array<string, string>  $cells
     */
    private static function text(array $cells, string $column): ?string
    {
        $value = $cells[$column] ?? '';

        return $value === '' ? null : $value;
    }

    /**
     * Several values in one cell, separated by a pipe.
     *
     * A pipe rather than a comma because a comma in a CSV cell means quoting,
     * and "chicken tikka, tikka chicken" is exactly the kind of cell somebody
     * types without the quotes.
     *
     * @param  array<string, string>  $cells
     * @return list<string>|null
     */
    private static function list(array $cells, string $column): ?array
    {
        $value = self::text($cells, $column);

        if ($value === null) {
            return null;
        }

        $parts = array_values(array_filter(array_map(trim(...), explode('|', $value)), fn (string $p): bool => $p !== ''));

        return $parts === [] ? null : $parts;
    }
}
