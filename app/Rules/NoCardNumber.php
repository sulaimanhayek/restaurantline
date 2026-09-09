<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Refuses any free-text field that contains something shaped like a card number.
 *
 * restaurantline never takes card details over the voice line — payment is an
 * SMS link after the call, or cash on delivery. That is a design constraint,
 * not a preference, and the README says so: a voice agent that reads card
 * numbers into a transcript, a webhook payload and a call recording has built a
 * cardholder-data environment by accident, and the person who forked this repo
 * to build a takeaway line is now in scope for PCI DSS without knowing it.
 *
 * Documenting the constraint is not enough on its own, because the thing that
 * breaks it is not a developer choosing to — it is a caller volunteering their
 * number unprompted and a language model helpfully putting it in the delivery
 * notes. So the rule is enforced rather than stated, on every free-text field
 * an agent endpoint accepts.
 *
 * Luhn is what separates a card number from a phone number, an order reference
 * or a long house number. Rejecting every run of thirteen digits would fail
 * honest input; rejecting only what passes Luhn is a narrow, deliberate net.
 */
final class NoCardNumber implements ValidationRule
{
    /**
     * @param  Closure(string, string|null=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        // Callers say card numbers with spaces, hyphens or in groups of four,
        // and speech-to-text writes them down that way. Strip the separators
        // before looking, or the check is trivially defeated by the way people
        // actually talk.
        $digits = (string) preg_replace('/[^0-9]/', '', $value);

        if (mb_strlen($digits) < 13) {
            return;
        }

        foreach ($this->runs($digits) as $candidate) {
            if ($this->passesLuhn($candidate)) {
                $fail('The :attribute must not contain card details. This system never takes card numbers by phone.');

                return;
            }
        }
    }

    /**
     * Every 13-to-19 digit window in the string.
     *
     * A window rather than the whole string, because "call me on 07700900123
     * card 4111111111111111" is one field with a card number buried in it.
     *
     * @return iterable<string>
     */
    private function runs(string $digits): iterable
    {
        $length = mb_strlen($digits);

        for ($size = 13; $size <= 19; $size++) {
            for ($offset = 0; $offset + $size <= $length; $offset++) {
                yield mb_substr($digits, $offset, $size);
            }
        }
    }

    private function passesLuhn(string $number): bool
    {
        $sum = 0;
        $double = false;

        for ($i = mb_strlen($number) - 1; $i >= 0; $i--) {
            $digit = (int) $number[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
