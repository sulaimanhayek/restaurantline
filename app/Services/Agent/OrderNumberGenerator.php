<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Models\Order;
use App\Models\Restaurant;
use RuntimeException;

/**
 * Short order references a person can say and hear.
 *
 * "A4721", read back as "A, four seven two one". Five characters, because the
 * caller has to remember it long enough to say it at the counter and the
 * kitchen has to shout it across a pass.
 *
 * Three deliberate exclusions from the alphabet, all learned the same way:
 *
 *  - **I, O** — indistinguishable from 1 and 0 when spoken or handwritten.
 *  - **S** — heard as F down a bad phone line often enough to matter.
 *
 * Sequential numbering was the obvious alternative and is worse: it leaks how
 * many orders a restaurant takes, and it collides badly the moment two calls
 * land in the same second.
 */
final class OrderNumberGenerator
{
    /** No I, no O, no S. */
    private const LETTERS = 'ABCDEFGHJKLMNPQRTUVWXYZ';

    private const ATTEMPTS = 20;

    public function generate(Restaurant $restaurant): string
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $candidate = sprintf(
                '%s%04d',
                self::LETTERS[random_int(0, mb_strlen(self::LETTERS) - 1)],
                random_int(0, 9999),
            );

            $taken = Order::query()
                ->where('restaurant_id', $restaurant->id)
                ->where('order_number', $candidate)
                ->exists();

            if (! $taken) {
                return $candidate;
            }
        }

        // 230,000 possible numbers. Twenty collisions in a row means the space
        // is genuinely full, and quietly reusing a number would put two live
        // orders on one reference at the pass.
        throw new RuntimeException(
            'Could not allocate an unused order number after '.self::ATTEMPTS.' attempts.',
        );
    }
}
