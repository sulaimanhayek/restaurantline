<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\Money;
use Filament\Forms\Components\TextInput;

/**
 * A price field that shows pounds and stores pence.
 *
 * Every price column in this schema is an integer in the currency's minor unit
 * (see docs/DECISIONS.md #0001), which is right for arithmetic and wrong for
 * humans — nobody types 1250 when they mean twelve fifty. This is the single
 * place the conversion happens, so a resource that forgets it does not exist.
 *
 * The rounding on the way in matters: `(int) (12.50 * 100)` is 1249 on some
 * platforms, and a menu that is a penny out everywhere is a bug someone finds
 * three months later in an accounts reconciliation.
 *
 * Both closures below take `mixed` deliberately. `numeric()` installs
 * Filament's NumberStateCast, which runs `floatval()` over the state on the way
 * out — so `dehydrateStateUsing` is handed a float. Filament's own code is not
 * under `strict_types`, so a narrower union like `int|string|null` does not
 * raise a TypeError there; PHP silently coerces, picks `int`, and 2.99 becomes
 * 2, which is stored as 200 pence. It looks like a rounding bug and is really a
 * type-juggling one. See docs/DECISIONS.md #0031.
 */
final class MoneyInput
{
    public static function make(string $name, string $currency): TextInput
    {
        return TextInput::make($name)
            ->numeric()
            ->prefix(self::symbol($currency))
            ->step('0.01')
            ->formatStateUsing(fn (mixed $state): ?string => blank($state)
                ? null
                : number_format(((int) $state) / 100, 2, '.', ''))
            ->dehydrateStateUsing(fn (mixed $state): int => blank($state)
                ? 0
                : (int) round(((float) $state) * 100));
    }

    /**
     * Whatever the locale puts in front of the number — "£", "$", "€".
     *
     * Derived from Money's own formatting rather than a hand-kept lookup table,
     * so an install that switches currency gets the right symbol without
     * anybody editing a map.
     */
    public static function symbol(string $currency): string
    {
        $zero = Money::zero($currency)->format();

        return trim(preg_replace('/[\d.,\s\x{00A0}]/u', '', $zero) ?? $currency);
    }
}
