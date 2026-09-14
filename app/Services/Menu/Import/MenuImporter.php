<?php

declare(strict_types=1);

namespace App\Services\Menu\Import;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\ModifierGroup;
use App\Models\Restaurant;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Puts a menu file into the database, twice if you like.
 *
 * Identity is the slug, scoped to the restaurant, and the slug comes from the
 * name unless the file gives one. That single choice is what makes the whole
 * thing idempotent: importing the same file twice updates twenty rows instead
 * of creating forty, and importing a corrected file changes only the dishes
 * that changed. It also means renaming a dish in the source file creates a new
 * one — so a file that intends a rename says `"slug"` explicitly, and the
 * dry-run output makes an accidental rename obvious before it happens.
 *
 * Nothing is ever deleted. `--prune` marks what has fallen out of the file as
 * unavailable, which is what "we've stopped doing that" means to a kitchen and
 * to the agent, and leaves the row attached to the orders that reference it.
 *
 * The whole import is one transaction. A menu half-applied because row two
 * hundred had a typo is worse than no import at all: the agent would spend the
 * evening confidently quoting a menu nobody meant to publish.
 *
 * @see docs/DECISIONS.md #0041
 */
final class MenuImporter
{
    /**
     * @param  array{categories: list<array<string, mixed>>, modifier_groups: list<array<string, mixed>>}  $data
     */
    public function import(Restaurant $restaurant, array $data, bool $prune = false): MenuImportReport
    {
        return DB::transaction(fn (): MenuImportReport => $this->apply($restaurant, $data, $prune));
    }

    /**
     * A dry run: everything the real one does, then nothing.
     *
     * Rolling a real import back is far more honest than a second code path
     * that predicts what an import would do. This exercises the same slugs, the
     * same validation and the same unique constraints, so a file that survives
     * a dry run survives the import.
     *
     * @param  array{categories: list<array<string, mixed>>, modifier_groups: list<array<string, mixed>>}  $data
     */
    public function dryRun(Restaurant $restaurant, array $data, bool $prune = false): MenuImportReport
    {
        DB::beginTransaction();

        try {
            return $this->apply($restaurant, $data, $prune);
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  array{categories: list<array<string, mixed>>, modifier_groups: list<array<string, mixed>>}  $data
     */
    private function apply(Restaurant $restaurant, array $data, bool $prune): MenuImportReport
    {
        $report = new MenuImportReport;

        $groups = $this->modifierGroups($restaurant, $data['modifier_groups'], $report);
        $this->categories($restaurant, $data['categories'], $groups, $report);

        if ($prune) {
            $this->prune($restaurant, $report);
        }

        return $report;
    }

    // -----------------------------------------------------------------------
    // Walking the file
    // -----------------------------------------------------------------------

    /**
     * @param  list<array<string, mixed>>  $definitions
     * @return array<string, ModifierGroup> Slug => group, for the items to attach to.
     */
    private function modifierGroups(Restaurant $restaurant, array $definitions, MenuImportReport $report): array
    {
        $groups = [];

        foreach ($definitions as $position => $definition) {
            $where = sprintf('modifier group %d', $position + 1);
            $name = $this->name($definition, $where);
            $slug = $this->slug($definition, $name);

            $group = $restaurant->modifierGroups()->firstOrNew(['slug' => $slug]);

            $this->fill($group, $this->given([
                'name' => $name,
                'prompt' => $this->string($definition, 'prompt'),
                'selection_type' => $this->string($definition, 'selection_type') ?? 'single',
                'min_selections' => $this->integer($definition, 'min_selections', $where),
                'max_selections' => $this->integer($definition, 'max_selections', $where),
                'is_required' => $this->boolean($definition, 'is_required'),
                'sort_order' => $this->integer($definition, 'sort_order', $where) ?? $position,
            ]), $report, 'modifier group', $name, $slug);

            $groups[$slug] = $group;

            $this->modifiers($restaurant, $group, $this->children($definition, 'modifiers', $where), $report);
        }

        return $groups;
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     */
    private function modifiers(Restaurant $restaurant, ModifierGroup $group, array $definitions, MenuImportReport $report): void
    {
        foreach ($definitions as $position => $definition) {
            $where = sprintf('modifier group "%s", modifier %d', $group->slug, $position + 1);
            $name = $this->name($definition, $where);

            /*
             * Prefixed with the group, because "Large" is a perfectly ordinary
             * name for an option in three different groups and a
             * restaurant-wide unique slug would collapse all three into one.
             */
            $slug = $this->slug($definition, $group->slug.'-'.$name);

            $modifier = $restaurant->modifiers()->firstOrNew(['slug' => $slug]);

            $this->fill($modifier, $this->given([
                'modifier_group_id' => $group->id,
                'name' => $name,
                'price_delta' => $this->price($definition, 'price_delta', $restaurant, $where) ?? 0,
                'kind' => $this->string($definition, 'kind') ?? 'option',
                'spoken_aliases' => $this->aliases($definition),
                'is_available' => $this->boolean($definition, 'is_available'),
                'is_default' => $this->boolean($definition, 'is_default'),
                'sort_order' => $this->integer($definition, 'sort_order', $where) ?? $position,
            ]), $report, 'modifier', $name, $slug);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     * @param  array<string, ModifierGroup>  $groups
     */
    private function categories(Restaurant $restaurant, array $definitions, array $groups, MenuImportReport $report): void
    {
        foreach ($definitions as $position => $definition) {
            $where = sprintf('category %d', $position + 1);
            $name = $this->name($definition, $where);
            $slug = $this->slug($definition, $name);

            $category = $restaurant->menuCategories()->firstOrNew(['slug' => $slug]);

            $this->fill($category, $this->given([
                'name' => $name,
                'description' => $this->string($definition, 'description'),
                'sort_order' => $this->integer($definition, 'sort_order', $where) ?? $position,
                'is_active' => $this->boolean($definition, 'is_active'),
                'available_from' => $this->time($definition, 'available_from', $where),
                'available_until' => $this->time($definition, 'available_until', $where),
                'available_days' => $this->days($definition, $where),
            ]), $report, 'category', $name, $slug);

            $this->items($restaurant, $category, $this->children($definition, 'items', $where), $groups, $report);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $definitions
     * @param  array<string, ModifierGroup>  $groups
     */
    private function items(
        Restaurant $restaurant,
        MenuCategory $category,
        array $definitions,
        array $groups,
        MenuImportReport $report,
    ): void {
        foreach ($definitions as $position => $definition) {
            $name = $this->name($definition, sprintf('category "%s", item %d', $category->slug, $position + 1));
            $where = sprintf('"%s" in category "%s"', $name, $category->slug);
            $slug = $this->slug($definition, $name);

            $price = $this->price($definition, 'price', $restaurant, $where);

            if ($price === null) {
                throw MenuImportException::at($where, 'there is no price. Prices are in '
                    .'pounds and pence, not pence: 6.50 is six pounds fifty.');
            }

            $item = $restaurant->menuItems()->firstOrNew(['slug' => $slug]);

            $this->fill($item, $this->given([
                'menu_category_id' => $category->id,
                'name' => $name,
                'description' => $this->string($definition, 'description'),
                'price' => $price,
                'sku' => $this->string($definition, 'sku'),
                'is_available' => $this->boolean($definition, 'is_available'),
                'spoken_aliases' => $this->aliases($definition),
                'sort_order' => $this->integer($definition, 'sort_order', $where) ?? $position,
                'prep_minutes' => $this->integer($definition, 'prep_minutes', $where),
            ]), $report, 'item', $name, $slug);

            $this->attachGroups($item, $definition, $groups, $where);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, ModifierGroup>  $groups
     */
    private function attachGroups(MenuItem $item, array $definition, array $groups, string $where): void
    {
        $wanted = $definition['modifier_groups'] ?? null;

        /*
         * Absent means "leave whatever is attached alone", which is what makes
         * a CSV of new prices safe to import over a menu whose sizes and extras
         * were set up in the dashboard. An empty list is a different statement:
         * it means detach them.
         */
        if (! is_array($wanted)) {
            return;
        }

        $ids = [];

        foreach ($wanted as $slug) {
            if (! is_string($slug)) {
                throw MenuImportException::at($where, '"modifier_groups" is a list of group slugs.');
            }

            $group = $groups[$slug] ?? ModifierGroup::query()
                ->where('restaurant_id', $item->restaurant_id)
                ->where('slug', $slug)
                ->first();

            if ($group === null) {
                throw MenuImportException::at($where, sprintf(
                    'there is no modifier group "%s". Define it under "modifier_groups" in this file, '
                    .'or in the dashboard, before attaching it to a dish.',
                    $slug,
                ));
            }

            $ids[] = $group->id;
        }

        $item->modifierGroups()->sync($ids);
    }

    /**
     * Take off the menu whatever the file no longer mentions.
     *
     * Marked unavailable, never deleted. A dish has order history hanging off
     * it and a name the matcher may still hear; what a restaurant means by "we
     * don't do that any more" is that it cannot be ordered tonight, and an
     * `is_available` flag says exactly that and nothing more.
     *
     * Only rows that are currently on the menu are touched, so running this
     * twice reports nothing the second time.
     */
    private function prune(Restaurant $restaurant, MenuImportReport $report): void
    {
        $items = $restaurant->menuItems()
            ->where('is_available', true)
            ->whereNotIn('slug', $report->touched('item'))
            ->get();

        foreach ($items as $item) {
            $item->update(['is_available' => false]);
            $report->record('item', 'withdrawn', $item->name, $item->slug);
        }

        $categories = $restaurant->menuCategories()
            ->where('is_active', true)
            ->whereNotIn('slug', $report->touched('category'))
            ->get();

        foreach ($categories as $category) {
            $category->update(['is_active' => false]);
            $report->record('category', 'withdrawn', $category->name, $category->slug);
        }
    }

    // -----------------------------------------------------------------------
    // Reading a definition
    // -----------------------------------------------------------------------

    /**
     * Apply the attributes and record which of the three things happened.
     *
     * "Unchanged" is worth distinguishing from "updated". A re-import that
     * reports two hundred updates tells you nothing; one that reports two
     * updates and a hundred and ninety-eight unchanged tells you exactly what
     * the corrected file corrected.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function fill(
        Model $model,
        array $attributes,
        MenuImportReport $report,
        string $kind,
        string $name,
        string $slug,
    ): void {
        $model->fill($attributes);

        $action = match (true) {
            ! $model->exists => 'created',
            $model->isDirty() => 'updated',
            default => 'unchanged',
        };

        $model->save();

        $report->record($kind, $action, $name, $slug);
    }

    /**
     * Drop the keys the file did not speak to.
     *
     * A null here means "the file is silent", not "set this to null", and a
     * silent file must never blank a description somebody wrote in the
     * dashboard. Every reader below returns null for absent, so this one filter
     * is the whole of that rule.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function given(array $attributes): array
    {
        return array_filter($attributes, static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<array<string, mixed>>
     */
    private function children(array $definition, string $key, string $where): array
    {
        $children = $definition[$key] ?? [];

        if (! is_array($children)) {
            throw MenuImportException::at($where, sprintf('"%s" must be a list.', $key));
        }

        return array_values(array_filter($children, is_array(...)));
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function name(array $definition, string $where): string
    {
        $name = $this->string($definition, 'name');

        if ($name === null) {
            throw MenuImportException::at($where, 'there is no "name".');
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function slug(array $definition, string $fallback): string
    {
        return $this->string($definition, 'slug') ?? Str::slug($fallback);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function string(array $definition, string $key): ?string
    {
        $value = $definition[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function integer(array $definition, string $key, string $where): ?int
    {
        $value = $definition[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            throw MenuImportException::at($where, sprintf(
                '"%s" should be a whole number, and this is %s.',
                $key,
                is_scalar($value) ? '"'.((string) $value).'"' : 'not',
            ));
        }

        return (int) $value;
    }

    /**
     * Whatever a person meant by yes.
     *
     * Spreadsheets say "yes", "Y", "TRUE" and "1"; a JSON file says `true`.
     * Anything unrecognised is treated as silence rather than as false, because
     * withdrawing a dish from the menu because its availability cell said "in
     * season" is not a defensible reading of that cell.
     *
     * @param  array<string, mixed>  $definition
     */
    private function boolean(array $definition, string $key): ?bool
    {
        $value = $definition[$key] ?? null;

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1 ? true : ($value === 0 ? false : null);
        }

        return match (is_string($value) ? strtolower(trim($value)) : null) {
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => null,
        };
    }

    /**
     * A wall-clock time, normalised to what the column stores.
     *
     * `"17:00"` is what a person writes and `17:00:00` is what Postgres hands
     * back, and without this the two never compare equal — which would make
     * every re-import report the same category as updated, and teach whoever
     * reads that output to stop reading it.
     *
     * @param  array<string, mixed>  $definition
     */
    private function time(array $definition, string $key, string $where): ?string
    {
        $value = $this->string($definition, $key);

        if ($value === null) {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $value, $matches) !== 1) {
            throw MenuImportException::at($where, sprintf(
                '"%s" should be a time of day like "17:00", and this is "%s".',
                $key,
                $value,
            ));
        }

        return sprintf('%02d:%02d:%02d', (int) $matches[1], (int) $matches[2], (int) ($matches[3] ?? 0));
    }

    /**
     * Days of the week a category is served on.
     *
     * Sunday is 0 and Saturday is 6, matching `MenuCategory::isServedAt()` and
     * the seeder. Not ISO, which starts the week on Monday at 1 — worth saying
     * out loud here, because getting it wrong takes a lunch menu off on the one
     * day the restaurant is busiest and nothing in the schema would object.
     *
     * @param  array<string, mixed>  $definition
     * @return list<int>|null
     */
    private function days(array $definition, string $where): ?array
    {
        $days = $definition['available_days'] ?? null;

        if (! is_array($days)) {
            return null;
        }

        $clean = [];

        foreach ($days as $day) {
            if (! is_numeric($day) || (int) $day < 0 || (int) $day > 6) {
                throw MenuImportException::at($where, '"available_days" is a list of weekday '
                    .'numbers, 0 for Sunday through 6 for Saturday.');
            }

            $clean[] = (int) $day;
        }

        sort($clean);

        return $clean === [] ? null : array_values(array_unique($clean));
    }

    /**
     * A price in major units, however it was written.
     *
     * @param  array<string, mixed>  $definition
     */
    private function price(array $definition, string $key, Restaurant $restaurant, string $where): ?int
    {
        $value = $definition[$key] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            throw MenuImportException::at($where, sprintf('"%s" is not a price.', $key));
        }

        try {
            return Money::parse((string) $value, $restaurant->currency)->amount;
        } catch (Throwable) {
            throw MenuImportException::at($where, sprintf(
                '"%s" is not a price I can read. Write it in pounds and pence — 6.50, or £6.50 — not in pence.',
                (string) $value,
            ));
        }
    }

    /**
     * Other things a caller might call this.
     *
     * The single most valuable column in a menu file, and the one nobody thinks
     * to fill in. "Chicken teeka", "the tikka", "number forty-two": every one of
     * them is a call that gets taken rather than escalated.
     *
     * @param  array<string, mixed>  $definition
     * @return list<string>|null
     */
    private function aliases(array $definition): ?array
    {
        $aliases = $definition['aliases'] ?? $definition['spoken_aliases'] ?? null;

        if (! is_array($aliases)) {
            return null;
        }

        $clean = array_values(array_filter(
            array_map(static fn (mixed $alias): string => is_string($alias) ? trim($alias) : '', $aliases),
            static fn (string $alias): bool => $alias !== '',
        ));

        return $clean === [] ? null : $clean;
    }
}
