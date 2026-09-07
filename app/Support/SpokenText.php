<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Turning what a caller said into something worth matching against.
 *
 * Speech-to-text hands you whole sentences, not search terms: "erm yeah can I
 * get a couple of the peri peri chicken please". Everything in that string
 * except "peri peri chicken" actively hurts a fuzzy match — filler words drag
 * the similarity score down and the quantity belongs on the order line, not in
 * the query.
 *
 * These are string operations only. Nothing here decides what a caller meant;
 * that is MenuMatchingService's job.
 */
final class SpokenText
{
    /**
     * Politeness and hesitation. Removed before matching.
     *
     * Order matters: the longest phrases go first, so "can I get" is gone
     * before the bare "get" would have been considered.
     *
     * @var list<string>
     */
    private const FILLER = [
        'could i please get', 'could i please have', 'can i please get', 'can i please have',
        'i would like to order', 'i would like to get', 'i would like to have',
        'can i get', 'can i have', 'could i get', 'could i have', 'may i have',
        'i would like', "i'd like", 'id like', 'i will have', "i'll have", 'ill have',
        'we would like', "we'd like", 'we will have', "we'll have",
        'let me get', 'let me have', 'give me', 'i want', 'we want',
        'do you have', 'have you got',
        'please', 'thanks', 'thank you', 'cheers',
        'erm', 'ermm', 'umm', 'um', 'uh', 'er', 'ah', 'yeah', 'yep', 'ok', 'okay',
        'just', 'like', 'some', 'the', 'a', 'an',
    ];

    /**
     * Words for small numbers. Callers say "two", never "2".
     *
     * @var array<string, int>
     */
    private const NUMBER_WORDS = [
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'eleven' => 11, 'twelve' => 12, 'a couple' => 2, 'couple' => 2,
        'a few' => 3, 'few' => 3, 'half a dozen' => 6, 'dozen' => 12,
    ];

    /**
     * Ways of saying "leave it out".
     *
     * "free" is deliberately absent: "gluten-free bun" is a swap, not a
     * removal, and listing it here quietly turned every gluten-free order into
     * a request to hold the bun.
     *
     * Used to decide whether a modifier query is asking to remove something.
     * Without this, a caller saying "onions" and a caller saying "no onions"
     * look identical to a trigram matcher — the second word carries the entire
     * meaning and only three characters of the signal.
     *
     * @var list<string>
     */
    private const NEGATIONS = [
        'no', 'not', 'none', 'without', 'hold', 'skip', 'leave out',
        'leave off', 'take off', 'minus', 'sans',
    ];

    /**
     * Lowercase, unaccented, punctuation-free, single-spaced.
     *
     * Deliberately keeps digits and negations: "6 wings" and "no onions" both
     * lose their meaning without them.
     */
    public static function normalise(string $text): string
    {
        $text = Str::ascii(mb_strtolower(trim($text)));
        $text = str_replace('&', ' and ', $text);
        $text = (string) preg_replace('/[^a-z0-9 ]+/', ' ', $text);

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Normalise, then strip the words that carry no menu information.
     *
     * Returns the original normalised string if stripping would empty it — a
     * caller who says nothing but "the" is better served by a bad match than by
     * a query with nothing in it.
     */
    public static function forMatching(string $text): string
    {
        $normalised = self::normalise($text);
        $stripped = $normalised;

        foreach (self::FILLER as $phrase) {
            $stripped = (string) preg_replace('/\b'.preg_quote($phrase, '/').'\b/', ' ', $stripped);
        }

        $stripped = trim((string) preg_replace('/\s+/', ' ', $stripped));

        return $stripped !== '' ? $stripped : $normalised;
    }

    /**
     * Pull a leading quantity off the front of a phrase.
     *
     * "two peri peri chickens" becomes 2 and "peri peri chickens". A phrase
     * with no leading number comes back as 1 and itself, so callers can be
     * fed through this unconditionally.
     *
     * @return array{quantity: int, text: string}
     */
    public static function extractQuantity(string $text): array
    {
        $normalised = self::normalise($text);

        // Multi-word quantities first — "half a dozen" must not be read as
        // "half" followed by the article "a".
        foreach (self::NUMBER_WORDS as $word => $value) {
            if (! str_contains($word, ' ')) {
                continue;
            }

            if (preg_match('/^'.preg_quote($word, '/').'\b\s*(?:of\s+)?(.*)$/', $normalised, $matches) === 1) {
                return ['quantity' => $value, 'text' => trim($matches[1])];
            }
        }

        if (preg_match('/^(\d+)\s*(?:x\s*)?(?:of\s+)?(.*)$/', $normalised, $matches) === 1) {
            return ['quantity' => max(1, (int) $matches[1]), 'text' => trim($matches[2])];
        }

        foreach (self::NUMBER_WORDS as $word => $value) {
            if (str_contains($word, ' ')) {
                continue;
            }

            if (preg_match('/^'.preg_quote($word, '/').'\b\s*(?:of\s+)?(.*)$/', $normalised, $matches) === 1) {
                return ['quantity' => $value, 'text' => trim($matches[1])];
            }
        }

        return ['quantity' => 1, 'text' => $normalised];
    }

    /**
     * Is this phrase asking for something to be left out?
     */
    public static function isNegated(string $text): bool
    {
        $normalised = self::normalise($text);

        foreach (self::NEGATIONS as $negation) {
            if (preg_match('/\b'.preg_quote($negation, '/').'\b/', $normalised) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Strip the negation words, leaving the thing being refused.
     *
     * "without any onions please" becomes "any onions", which is what you want
     * to match against a modifier named "Onions". The filler goes too: a
     * trailing "please" costs a trigram match as much on this path as it does
     * on the menu one.
     */
    public static function stripNegation(string $text): string
    {
        $text = self::normalise($text);

        foreach (self::NEGATIONS as $negation) {
            $text = (string) preg_replace('/\b'.preg_quote($negation, '/').'\b/', ' ', $text);
        }

        return self::forMatching($text);
    }
}
