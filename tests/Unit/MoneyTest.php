<?php

declare(strict_types=1);

use App\Support\Money;

it('holds minor units without touching floats', function (): void {
    expect(Money::of(1250)->amount)->toBe(1250)
        ->and(Money::of(1250)->currency)->toBe('GBP')
        ->and(Money::zero()->isZero())->toBeTrue();
});

it('parses the shapes a menu import actually contains', function (string $input, int $expected): void {
    expect(Money::parse($input)->amount)->toBe($expected);
})->with([
    ['12.50', 1250],
    ['£12.50', 1250],
    ['12,50', 1250],
    ['1,234.56', 123456],
    ['9', 900],
    ['0.05', 5],
    ['  7.99  ', 799],
]);

it('refuses to guess at a price it cannot read', function (): void {
    expect(fn (): Money => Money::parse('twelve fifty'))
        ->toThrow(InvalidArgumentException::class);
});

it('formats amounts for a voice agent to read aloud', function (int $amount, string $expected): void {
    expect(Money::of($amount)->spoken())->toBe($expected);
})->with([
    'whole pounds drop the pence' => [1700, '17 pounds'],
    'one pound is singular' => [100, '1 pound'],
    'pence read as a pair' => [1410, '14 pounds 10'],
    'single-digit pence keep the unit' => [1405, '14 pounds 5 pence'],
    'under a pound' => [60, '60 pence'],
    'zero' => [0, '0 pounds'],
    'a discount says so' => [-250, 'minus 2 pounds 50'],
]);

it('speaks other currencies with their own units', function (): void {
    expect(Money::of(1250, 'USD')->spoken())->toBe('12 dollars 50')
        ->and(Money::of(105, 'EUR')->spoken())->toBe('1 euro 5 cents');
});

it('formats for the screen with a currency symbol', function (): void {
    expect((string) Money::of(1250))->toBe('£12.50')
        ->and((string) Money::of(0))->toBe('£0.00');
});
