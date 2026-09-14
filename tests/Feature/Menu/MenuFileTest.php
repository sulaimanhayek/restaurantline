<?php

declare(strict_types=1);

use App\Services\Menu\Import\MenuFile;
use App\Services\Menu\Import\MenuImportException;

/**
 * Reading a menu file, before anything touches the database.
 *
 * Everything here is about the first hour with the repo: a freelancer with a
 * spreadsheet an owner emailed them. Every failure in this file is a message
 * that has to say which line and what to do about it, because the alternative
 * is somebody staring at "Array to string conversion" with a restaurant waiting.
 */
function menuFile(string $name, string $contents): string
{
    $path = sys_get_temp_dir().'/'.uniqid('menu-', true).'-'.$name;
    file_put_contents($path, $contents);

    return $path;
}

describe('JSON', function (): void {
    it('reads categories, items and modifier groups', function (): void {
        $data = MenuFile::read(menuFile('menu.json', (string) json_encode([
            'categories' => [
                ['name' => 'Starters', 'items' => [['name' => 'Bhaji', 'price' => '4.20']]],
            ],
            'modifier_groups' => [
                ['name' => 'Size', 'slug' => 'size', 'modifiers' => [['name' => 'Large', 'price_delta' => '2.00']]],
            ],
        ])));

        expect($data['categories'])->toHaveCount(1)
            ->and($data['categories'][0]['name'])->toBe('Starters')
            ->and($data['modifier_groups'])->toHaveCount(1)
            ->and($data['modifier_groups'][0]['slug'])->toBe('size');
    });

    it('does not insist on modifier groups', function (): void {
        $data = MenuFile::read(menuFile('menu.json', (string) json_encode([
            'categories' => [['name' => 'Sides', 'items' => []]],
        ])));

        expect($data['modifier_groups'])->toBe([]);
    });

    it('says where the JSON broke rather than just that it did', function (): void {
        expect(fn () => MenuFile::read(menuFile('menu.json', '{"categories": [},')))
            ->toThrow(MenuImportException::class, 'not valid JSON');
    });

    it('refuses a file with no categories', function (): void {
        expect(fn () => MenuFile::read(menuFile('menu.json', '{"categories": []}')))
            ->toThrow(MenuImportException::class, 'categories');
    });
});

describe('CSV', function (): void {
    it('groups rows into the categories named in the category column', function (): void {
        $data = MenuFile::read(menuFile('menu.csv', <<<'CSV'
            category,name,price
            Starters,Onion Bhaji,4.20
            Mains,Chicken Korma,11.90
            Starters,Chicken Tikka,6.50
            CSV));

        expect($data['categories'])->toHaveCount(2)
            ->and($data['categories'][0]['name'])->toBe('Starters')
            ->and($data['categories'][0]['items'])->toHaveCount(2)
            ->and($data['categories'][1]['items'][0]['name'])->toBe('Chicken Korma');
    });

    /*
     * "Item Name", "item_name" and "ITEMNAME" are one column, and making
     * somebody rename a header before their first import is a poor welcome.
     */
    it('does not care how the headers are spelled', function (): void {
        $data = MenuFile::read(menuFile('menu.csv', <<<'CSV'
            Category,Name,Price,Prep Minutes,Modifier_Groups
            Mains,Korma,11.90,20,size
            CSV));

        expect($data['categories'][0]['items'][0])
            ->toMatchArray(['name' => 'Korma', 'price' => '11.90', 'prep_minutes' => '20'])
            ->and($data['categories'][0]['items'][0]['modifier_groups'])->toBe(['size']);
    });

    it('splits multi-value cells on a pipe, so a comma can stay a comma', function (): void {
        $data = MenuFile::read(menuFile('menu.csv', <<<'CSV'
            category,name,price,aliases
            Mains,Chicken Korma,11.90,korma | the korma |chicken curry
            CSV));

        expect($data['categories'][0]['items'][0]['aliases'])
            ->toBe(['korma', 'the korma', 'chicken curry']);
    });

    it('ignores blank lines rather than choking on them', function (): void {
        $data = MenuFile::read(menuFile('menu.csv', "category,name,price\nMains,Korma,11.90\n\n\n"));

        expect($data['categories'][0]['items'])->toHaveCount(1);
    });

    it('names the missing column', function (): void {
        expect(fn () => MenuFile::read(menuFile('menu.csv', "category,name\nMains,Korma")))
            ->toThrow(MenuImportException::class, 'price');
    });

    it('names the line a row went wrong on', function (): void {
        expect(fn () => MenuFile::read(menuFile('menu.csv', "category,name,price\nMains,Korma,11.90\n,Bhaji,4.20")))
            ->toThrow(MenuImportException::class, 'line 3');
    });

    /*
     * A CSV cannot define modifier groups — see the class docblock — and the
     * important half of that decision is that it can still reference them.
     */
    it('defines no modifier groups of its own', function (): void {
        $data = MenuFile::read(menuFile('menu.csv', "category,name,price\nMains,Korma,11.90"));

        expect($data['modifier_groups'])->toBe([]);
    });
});

it('refuses a file it has never heard of', function (): void {
    expect(fn () => MenuFile::read(menuFile('menu.xlsx', 'binary nonsense')))
        ->toThrow(MenuImportException::class, '.json or .csv');
});

it('says so when the file is not there', function (): void {
    expect(fn () => MenuFile::read('/tmp/there-is-no-menu-here.json'))
        ->toThrow(MenuImportException::class, 'no such file');
});

it('says so when the file is empty', function (): void {
    expect(fn () => MenuFile::read(menuFile('menu.json', "   \n")))
        ->toThrow(MenuImportException::class, 'empty');
});
