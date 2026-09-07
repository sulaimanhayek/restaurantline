<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;
use NumberFormatter;
use Stringable;

/**
 * A monetary amount in a currency's minor unit.
 *
 * Every price in restaurantline is a plain integer in the database — pence,
 * cents — and stays an integer through the whole pricing path. This class
 * exists only at the edges: to format an amount for a human, and to parse one
 * from a spreadsheet during menu import.
 *
 * It deliberately does not wrap the arithmetic. PricingService adds integers
 * directly, which is exact by construction and needs no defending.
 *
 * @see docs/DECISIONS.md #0001
 */
final readonly class Money implements Stringable
{
    private function __construct(
        public int $amount,
        public string $currency,
    ) {}

    /**
     * @param  int  $amount  Minor units — 1250 is £12.50.
     */
    public static function of(int $amount, string $currency = 'GBP'): self
    {
        return new self($amount, strtoupper($currency));
    }

    public static function zero(string $currency = 'GBP'): self
    {
        return new self(0, strtoupper($currency));
    }

    /**
     * Parse a human-written price into minor units.
     *
     * Accepts the shapes a menu spreadsheet actually contains: "12.50",
     * "£12.50", "12,50", "12", " 12.5 ". Anything else is an error rather than
     * a silent zero, because a silently-zero price on an imported menu is the
     * kind of bug that gets noticed by a customer.
     *
     * @throws InvalidArgumentException
     */
    public static function parse(string $value, string $currency = 'GBP'): self
    {
        $cleaned = trim($value);

        // Strip currency symbols and thousands separators, then normalise a
        // comma decimal separator to a full stop.
        $cleaned = preg_replace('/[^\d.,\-]/u', '', $cleaned) ?? '';

        if ($cleaned === '' || $cleaned === '-') {
            throw new InvalidArgumentException(sprintf('Could not parse "%s" as a price.', $value));
        }

        // "1,234.56" — comma is a thousands separator.
        if (str_contains($cleaned, ',') && str_contains($cleaned, '.')) {
            $cleaned = str_replace(',', '', $cleaned);
        } elseif (str_contains($cleaned, ',')) {
            // "12,50" — comma is the decimal separator.
            $cleaned = str_replace(',', '.', $cleaned);
        }

        if (! is_numeric($cleaned)) {
            throw new InvalidArgumentException(sprintf('Could not parse "%s" as a price.', $value));
        }

        // round() before casting: (int) (12.50 * 100) is 1249 on some platforms.
        return new self((int) round(((float) $cleaned) * 100), strtoupper($currency));
    }

    /**
     * Format for display: "£12.50".
     */
    public function format(?string $locale = null): string
    {
        $formatter = new NumberFormatter($locale ?? 'en_GB', NumberFormatter::CURRENCY);

        return $formatter->formatCurrency($this->amount / 100, $this->currency)
            ?: sprintf('%s%.2f', $this->currency, $this->amount / 100);
    }

    /**
     * Format for the agent to read aloud.
     *
     * Speech synthesis handles "12 pounds 50" far more reliably than it handles
     * "£12.50", which some voices render as "pound twelve point five zero".
     * Whole amounts drop the trailing "zero pence" entirely, because "twelve
     * pounds and zero pence" is how a robot talks. Amounts under ten pence keep
     * the unit — "12 pounds 5" would be heard as twelve fifty.
     */
    public function spoken(): string
    {
        $major = intdiv(abs($this->amount), 100);
        $minor = abs($this->amount) % 100;
        // A negative amount is a discount or a cheaper swap. Saying it as a
        // positive number is the kind of bug a caller only notices at the door.
        $sign = $this->amount < 0 ? 'minus ' : '';

        [$majorWord, $minorWord] = match ($this->currency) {
            'GBP' => ['pound', 'pence'],
            'USD' => ['dollar', 'cent'],
            'EUR' => ['euro', 'cent'],
            default => [strtolower($this->currency), 'cent'],
        };

        $majorLabel = $major === 1 ? $majorWord : $majorWord.'s';

        $minorLabel = $minorWord === 'pence'
            ? 'pence'
            : ($minor === 1 ? $minorWord : $minorWord.'s');

        if ($minor === 0) {
            return $sign.sprintf('%d %s', $major, $majorLabel);
        }

        if ($major === 0) {
            return $sign.sprintf('%d %s', $minor, $minorLabel);
        }

        if ($minor < 10) {
            return $sign.sprintf('%d %s %d %s', $major, $majorLabel, $minor, $minorLabel);
        }

        return $sign.sprintf('%d %s %d', $major, $majorLabel, $minor);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
