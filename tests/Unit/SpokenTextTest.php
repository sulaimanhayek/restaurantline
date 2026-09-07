<?php

declare(strict_types=1);

use App\Support\SpokenText;

it('normalises the shapes speech-to-text actually produces', function (string $input, string $expected): void {
    expect(SpokenText::normalise($input))->toBe($expected);
})->with([
    'case and punctuation' => ['Peri-Peri Chicken & Chips!', 'peri peri chicken and chips'],
    'accents' => ['Café Crème', 'cafe creme'],
    'runs of whitespace' => ["  double   beef \n burger ", 'double beef burger'],
    'digits survive' => ['6 wings', '6 wings'],
    'negations survive' => ['no onions', 'no onions'],
    'nothing at all' => ['   ', ''],
]);

it('strips the politeness a caller wraps an order in', function (): void {
    expect(SpokenText::forMatching('erm yeah can I get a couple of the peri peri chicken please'))
        ->toBe('couple of peri peri chicken');
});

it('keeps "of" so a dish named after it survives', function (): void {
    // "Bucket of Wings" is a real menu item. Stripping "of" made it "bucket
    // wings", which scored worse against the caller's own words than it did
    // before the filler was removed.
    expect(SpokenText::forMatching('a bucket of wings'))->toBe('bucket of wings');
});

it('falls back to the whole phrase rather than matching on nothing', function (): void {
    expect(SpokenText::forMatching('the'))->toBe('the')
        ->and(SpokenText::forMatching('please'))->toBe('please');
});

it('pulls a leading quantity off the front of a phrase', function (string $input, int $quantity, string $text): void {
    expect(SpokenText::extractQuantity($input))->toBe(['quantity' => $quantity, 'text' => $text]);
})->with([
    'spoken number' => ['two chicken wraps', 2, 'chicken wraps'],
    'digits' => ['3 chips', 3, 'chips'],
    'times notation' => ['2 x chicken wraps', 2, 'chicken wraps'],
    'a couple' => ['a couple of chicken wraps', 2, 'chicken wraps'],
    'half a dozen beats "a"' => ['half a dozen wings', 6, 'wings'],
    'no quantity means one' => ['chips', 1, 'chips'],
    'zero is never an order' => ['0 chips', 1, 'chips'],
]);

it('does not read the start of a dish name as a number', function (): void {
    // "onion rings" begins with "one".
    expect(SpokenText::extractQuantity('onion rings'))
        ->toBe(['quantity' => 1, 'text' => 'onion rings']);
});

it('spots a caller asking for something to be left out', function (string $input, bool $negated): void {
    expect(SpokenText::isNegated($input))->toBe($negated);
})->with([
    ['no onions', true],
    ['without pickles', true],
    ['hold the mayo', true],
    ['leave out the cheese', true],
    ['not too spicy', true],
    ['extra onions', false],
    ['onions', false],
    // The regression that made every gluten-free order a request to hold the
    // bun: "free" is a swap word, not a negation.
    ['gluten free bun', false],
    ['dairy free', false],
]);

it('leaves the thing being refused once the negation is gone', function (string $input, string $expected): void {
    expect(SpokenText::stripNegation($input))->toBe($expected);
})->with([
    ['no onions', 'onions'],
    ['without any onions please', 'any onions'],
    ['hold the mayo', 'mayo'],
    ['can I get it without cheese', 'it cheese'],
]);
