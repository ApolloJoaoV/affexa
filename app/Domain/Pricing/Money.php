<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use InvalidArgumentException;
use NumberFormatter;
use RuntimeException;

/**
 * A monetary amount in BRL, held internally as an integer number of cents.
 *
 * Prices arrive from marketplaces as strings and floats and are compared against
 * historical medians to decide whether a promotion is real. Doing any of that in
 * floating point eventually produces a wrong discount, so no float ever reaches
 * the inside of this object.
 */
final readonly class Money
{
    private function __construct(public int $cents) {}

    public static function fromCents(int $cents): self
    {
        return new self($cents);
    }

    /**
     * Builds from a decimal representation, which is how both PostgreSQL numeric
     * and marketplace payloads deliver prices.
     */
    public static function fromNumericString(string $amount): self
    {
        $normalized = trim($amount);

        if (preg_match('/^-?\d+(\.\d+)?$/', $normalized) !== 1) {
            throw new InvalidArgumentException("Cannot read [{$amount}] as a monetary amount.");
        }

        $negative = str_starts_with($normalized, '-');
        $digits = ltrim($normalized, '-');

        [$whole, $fraction] = str_contains($digits, '.')
            ? explode('.', $digits, 2)
            : [$digits, '0'];

        // Round half up on the third decimal, matching numeric(12,2) in the database.
        $fraction = str_pad(substr($fraction, 0, 3), 3, '0');
        $cents = ((int) $whole) * 100 + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $cents++;
        }

        return new self($negative ? -$cents : $cents);
    }

    /**
     * Accepts a float only at the system boundary, where a JSON payload has
     * already forced one on us. Never used for arithmetic.
     */
    public static function fromFloat(float $amount): self
    {
        return self::fromNumericString(number_format($amount, 3, '.', ''));
    }

    /**
     * The representation written to a money_brl column.
     */
    public function toNumericString(): string
    {
        $sign = $this->cents < 0 ? '-' : '';
        $absolute = abs($this->cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100);
    }

    public function minus(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function isLessThan(self $other): bool
    {
        return $this->cents < $other->cents;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->cents > $other->cents;
    }

    /**
     * Formats for display, e.g. "R$ 1.299,90".
     */
    public function format(string $locale = 'pt_BR'): string
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $formatted = $formatter->formatCurrency($this->cents / 100, 'BRL');

        if ($formatted === false) {
            throw new RuntimeException("Unable to format [{$this->toNumericString()}] for locale [{$locale}].");
        }

        // ICU emits a non-breaking space after the symbol; normalise it so the
        // value is safe to embed in WhatsApp messages and card text.
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $formatted);
    }

    public function __toString(): string
    {
        return $this->toNumericString();
    }
}
