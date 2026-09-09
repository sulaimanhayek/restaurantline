<?php

declare(strict_types=1);

use App\Rules\NoCardNumber;
use Illuminate\Support\Facades\Validator;

/**
 * The rule that keeps card numbers out of the database.
 *
 * Worth testing on its own rather than only through the endpoints, because it
 * is the enforcement of a design constraint rather than a validation nicety.
 * The failure it prevents is silent: nothing breaks when a card number lands in
 * a delivery note, it just quietly puts the person who forked this repo in
 * scope for PCI DSS.
 */
function cardRuleAccepts(string $value): bool
{
    return Validator::make(['notes' => $value], ['notes' => [new NoCardNumber]])->passes();
}

describe('what it refuses', function (): void {
    it('refuses the test card numbers a caller might actually read out', function (string $number): void {
        expect(cardRuleAccepts($number))->toBeFalse();
    })->with([
        'visa' => '4111111111111111',
        'mastercard' => '5500005555555559',
        'amex, 15 digits' => '340000000000009',
        'discover' => '6011000000000004',
        'visa, 13 digits' => '4222222222222',
    ]);

    /**
     * How a person says a card number, and therefore how speech-to-text writes
     * it down. A rule that only looked at unbroken digit runs would be defeated
     * by the way everybody on earth reads out sixteen digits.
     */
    it('refuses a number spoken in groups', function (string $number): void {
        expect(cardRuleAccepts($number))->toBeFalse();
    })->with([
        'spaces' => '4111 1111 1111 1111',
        'hyphens' => '4111-1111-1111-1111',
        'mixed' => '4111 1111-1111 1111',
        'said slowly' => '4 1 1 1 1 1 1 1 1 1 1 1 1 1 1 1',
    ]);

    /**
     * The window is what matters here. A caller who volunteers a card number
     * mid-sentence produces one field with the number buried in it, and a check
     * anchored to the whole string would miss every one of them.
     */
    it('finds a card number buried in a sentence', function (): void {
        expect(cardRuleAccepts('ring the buzzer, my card is 4111 1111 1111 1111, thanks'))->toBeFalse();
    });
});

describe('what it lets through', function (): void {
    it('lets ordinary delivery notes through', function (string $value): void {
        expect(cardRuleAccepts($value))->toBeTrue();
    })->with([
        'a note' => 'ring the top buzzer, blue door',
        'a phone number' => 'call 07700 900123 when you arrive',
        'a flat number' => 'flat 12b, second floor',
        'a postcode' => 'the entrance is round the back on E1 6QR',
        'an empty string' => '',
        'a short digit run' => 'gate code 4821',
        'twelve digits' => 'reference 411111111111',
    ]);

    /**
     * Non-strings reach the rule when a caller sends the wrong type. Refusing
     * them here would produce a card-details message for what is really a
     * malformed request, so the type rules alongside it get to answer instead.
     */
    it('leaves a non-string to the rules that care about types', function (): void {
        expect(Validator::make(['notes' => 4111111111111111], ['notes' => [new NoCardNumber]])->passes())
            ->toBeTrue();
    });
});

/**
 * The deliberate bias, pinned so nobody "fixes" it later.
 *
 * Sliding a 13-to-19 digit window means a long enough run of digits will
 * eventually contain one that satisfies Luhn by chance, whatever the digits
 * actually mean. That is the direction to be wrong in: refusing an unusual note
 * costs a caller repeating themselves, and the other mistake costs a card
 * number in a transcript, a webhook payload and a call recording.
 */
it('errs towards refusal on a long run of digits that is not a card', function (): void {
    expect(cardRuleAccepts('gate code 1234 5678 9012 3456'))->toBeFalse();
});

it('says why, in words an operator reading the log will understand', function (): void {
    $validator = Validator::make(['notes' => '4111111111111111'], ['notes' => [new NoCardNumber]]);

    expect($validator->errors()->first('notes'))
        ->toContain('must not contain card details')
        ->toContain('never takes card numbers by phone');
});
