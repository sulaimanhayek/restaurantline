<?php

declare(strict_types=1);

use App\Support\SpokenTime;

it('says wall-clock times the way someone answering a phone says them', function (string $time, string $expected): void {
    expect(SpokenTime::of($time))->toBe($expected);
})->with([
    ['00:00:00', 'midnight'],
    ['12:00:00', 'midday'],
    ['09:00:00', '9am'],
    ['11:30:00', '11:30am'],
    ['17:00:00', '5pm'],
    ['22:30:00', '10:30pm'],
    ['00:30:00', '12:30am'],
    ['23:59:00', '11:59pm'],
    // Opening hours that run past midnight are stored as hours past 24.
    ['12:15', '12:15pm'],
]);

it('reads a shift as a range', function (): void {
    expect(SpokenTime::range('17:00:00', '22:30:00'))->toBe('5pm to 10:30pm')
        ->and(SpokenTime::range('12:00:00', '15:00:00'))->toBe('midday to 3pm');
});
