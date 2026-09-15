<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Restaurant;
use App\Services\Menu\Import\MenuFile;
use App\Services\Menu\Import\MenuImporter;
use App\Services\Menu\Import\MenuImportException;
use App\Services\Menu\Import\MenuImportReport;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Throwable;

/**
 * Gets a restaurant's actual menu into the database.
 *
 * The second command anybody runs, and usually the longest hour of the first
 * day, because a menu arrives as a PDF, a spreadsheet or a photograph of a
 * laminated card. This takes the two shapes worth automating — a CSV exported
 * from whatever the owner uses, and a JSON file you wrote once — and makes
 * running it again free, so the loop is: import, look at it, fix the file,
 * import again.
 *
 * `--dry-run` prints the whole menu with every price formatted as money and
 * changes nothing. Read that before the first real import. A price column
 * misread by a factor of a hundred is not a bug that announces itself; it is an
 * evening of the agent quoting four hundred and fifty pounds for a korma.
 *
 * @see docs/DECISIONS.md #0041
 */
final class ImportMenuCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'kitchenline:import-menu
        {file : Path to a .json or .csv menu file}
        {--dry-run : Print what would change, and change nothing}
        {--prune : Take off the menu anything the file does not mention}
        {--restaurant= : Slug of the restaurant to import into, if this install has more than one}
        {--force : Skip the confirmation in production}';

    protected $description = 'Import a menu from a JSON or CSV file, idempotently';

    public function handle(MenuImporter $importer): int
    {
        $restaurant = $this->restaurant();

        if ($restaurant === null) {
            return self::FAILURE;
        }

        $path = (string) $this->argument('file');

        try {
            $data = MenuFile::read($path);
        } catch (MenuImportException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Restaurant</>', $restaurant->name);
        $this->components->twoColumnDetail('<fg=gray>File</>', $path);
        $this->components->twoColumnDetail(
            '<fg=gray>Contains</>',
            sprintf(
                '%d categories, %d dishes, %d modifier groups',
                count($data['categories']),
                $this->countItems($data['categories']),
                count($data['modifier_groups']),
            ),
        );
        $this->newLine();

        $prune = (bool) $this->option('prune');

        if ($this->option('dry-run')) {
            $this->menu($restaurant, $data['categories']);

            try {
                $this->report($importer->dryRun($restaurant, $data, $prune), dryRun: true);
            } catch (MenuImportException $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }

            return self::SUCCESS;
        }

        if ($prune && ! $this->confirmPrune($restaurant)) {
            return self::FAILURE;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        try {
            $this->report($importer->import($restaurant, $data, $prune), dryRun: false);
        } catch (MenuImportException $exception) {
            /*
             * The import was one transaction, so there is nothing half-applied
             * to explain or undo. Saying so is worth a line: the instinct after
             * a failed import is to go and check, and that is an hour nobody
             * needs to spend.
             */
            $this->components->error($exception->getMessage());
            $this->components->warn('Nothing was imported. The whole file is applied together or not at all.');

            return self::FAILURE;
        }

        return self::SUCCESS;
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
            $this->components->error('There is no restaurant to import into. Run `php artisan migrate --seed` first.');
        }

        return $restaurant;
    }

    /**
     * The menu as the file reads it, with the prices spelled out as money.
     *
     * This is the whole value of `--dry-run`. Everything else it prints can be
     * inferred; this cannot, because the only way to know that the spreadsheet
     * meant £6.50 and not 6.50p is to see it written the way a customer would.
     *
     * @param  list<array<string, mixed>>  $categories
     */
    private function menu(Restaurant $restaurant, array $categories): void
    {
        foreach ($categories as $category) {
            $name = is_string($category['name'] ?? null) ? $category['name'] : '(unnamed)';
            $items = is_array($category['items'] ?? null) ? $category['items'] : [];

            $this->line(sprintf('  <options=bold>%s</>', $name));

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $this->components->twoColumnDetail(
                    '  '.(is_string($item['name'] ?? null) ? $item['name'] : '(unnamed)'),
                    $this->money($restaurant, $item['price'] ?? null),
                );
            }

            $this->newLine();
        }
    }

    private function money(Restaurant $restaurant, mixed $price): string
    {
        if (! is_string($price) && ! is_int($price) && ! is_float($price)) {
            return '<fg=red>no price</>';
        }

        try {
            return Money::parse((string) $price, $restaurant->currency)->format();
        } catch (Throwable) {
            return sprintf('<fg=red>%s?</>', (string) $price);
        }
    }

    private function report(MenuImportReport $report, bool $dryRun): void
    {
        $kinds = ['category' => 'categories', 'item' => 'dishes', 'modifier group' => 'modifier groups', 'modifier' => 'modifiers'];

        foreach ($kinds as $kind => $plural) {
            $counts = [];

            foreach (['created', 'updated', 'unchanged', 'withdrawn'] as $action) {
                if ($report->count($kind, $action) > 0) {
                    $counts[] = sprintf('%d %s', $report->count($kind, $action), $action);
                }
            }

            if ($counts !== []) {
                $this->components->twoColumnDetail('<fg=gray>'.ucfirst($plural).'</>', implode(', ', $counts));
            }
        }

        $this->newLine();

        /*
         * Withdrawals are the only thing here a person might not have meant, so
         * they are the only thing named rather than counted.
         */
        $this->withdrawals('Sections taken off the menu', $report->names('category', 'withdrawn'));
        $this->withdrawals('Dishes taken off the menu', $report->names('item', 'withdrawn'));

        if ($dryRun) {
            $this->components->info($report->changedAnything()
                ? 'Nothing was written. Run it again without --dry-run to apply this.'
                : 'Nothing was written, and nothing would change. The menu already matches the file.');

            return;
        }

        $this->components->info($report->changedAnything()
            ? 'Menu imported.'
            : 'The menu already matched the file. Nothing changed.');
    }

    /**
     * @param  list<string>  $names
     */
    private function withdrawals(string $heading, array $names): void
    {
        if ($names === []) {
            return;
        }

        $this->components->warn($heading.':');

        // A long tail of withdrawals is usually one wrong file, and the first
        // few names say so just as well as forty do.
        foreach (array_slice($names, 0, 10) as $name) {
            $this->line('    <fg=yellow>-</> '.$name);
        }

        if (count($names) > 10) {
            $this->line(sprintf('    <fg=gray>and %d more</>', count($names) - 10));
        }

        $this->newLine();
    }

    private function confirmPrune(Restaurant $restaurant): bool
    {
        $this->components->warn(sprintf(
            '--prune will take every dish and section not in this file off %s\'s menu. '
            .'Nothing is deleted — they are marked unavailable and can be switched back on in the dashboard.',
            $restaurant->name,
        ));

        return $this->confirm('Go ahead?', false);
    }

    /**
     * @param  list<array<string, mixed>>  $categories
     */
    private function countItems(array $categories): int
    {
        $count = 0;

        foreach ($categories as $category) {
            $items = $category['items'] ?? [];
            $count += is_array($items) ? count($items) : 0;
        }

        return $count;
    }
}
