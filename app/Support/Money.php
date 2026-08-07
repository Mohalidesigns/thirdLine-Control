<?php

namespace App\Support;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Immutable money value: integer minor units + ISO-4217 currency code (R7).
 * Floats are rejected at construction — rounding drift in a compliance
 * product is a reportable defect, not a nuisance.
 */
final class Money implements JsonSerializable
{
    /** Currencies the platform supports out of the box (R7). */
    public const SUPPORTED = ['NGN', 'GHS', 'KES', 'ZAR', 'USD', 'EUR', 'GBP'];

    /** Minor units per currency (all supported currencies use 2). */
    private const MINOR_UNIT_EXPONENT = 2;

    private const SYMBOLS = [
        'NGN' => '₦', 'GHS' => 'GH₵', 'KES' => 'KSh', 'ZAR' => 'R',
        'USD' => '$', 'EUR' => '€', 'GBP' => '£',
    ];

    private function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {}

    /**
     * @param  mixed  $minorUnits  strictly an integer count of minor units (kobo, pesewas, cents)
     */
    public static function of(mixed $minorUnits, string $currency): self
    {
        if (! is_int($minorUnits)) {
            throw new InvalidArgumentException(
                'Money amounts must be integer minor units — floats and strings are rejected (rule R7).'
            );
        }

        return new self($minorUnits, self::assertCurrency($currency));
    }

    /**
     * Parse a decimal string in major units ("1500.25") without ever
     * passing through a float.
     */
    public static function fromMajor(string $major, string $currency): self
    {
        $major = trim($major);

        if (! preg_match('/^-?\d+(\.\d{1,'.self::MINOR_UNIT_EXPONENT.'})?$/', $major)) {
            throw new InvalidArgumentException("Cannot parse '{$major}' as a decimal money amount.");
        }

        [$whole, $fraction] = array_pad(explode('.', ltrim($major, '-')), 2, '');
        $fraction = str_pad($fraction, self::MINOR_UNIT_EXPONENT, '0');
        $minor = (int) ($whole.$fraction);

        return self::of(str_starts_with($major, '-') ? -$minor : $minor, $currency);
    }

    public static function zero(string $currency): self
    {
        return self::of(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    public function multiply(int $factor): self
    {
        return new self($this->amount * $factor, $this->currency);
    }

    /**
     * Multiply by a decimal string factor (an FX rate, a percentage) using
     * bcmath — half-up rounding to the minor unit, no float involved.
     */
    public function multiplyByDecimal(string $factor): self
    {
        if (! preg_match('/^-?\d+(\.\d+)?$/', trim($factor))) {
            throw new InvalidArgumentException("Cannot parse '{$factor}' as a decimal factor.");
        }

        $raw = bcmul((string) $this->amount, trim($factor), self::MINOR_UNIT_EXPONENT + 2);
        $rounded = self::roundHalfUp($raw);

        return new self($rounded, $this->currency);
    }

    /**
     * Convert to another currency at the given decimal-string rate
     * (units of $target per one unit of $this->currency).
     */
    public function convert(string $targetCurrency, string $rate): self
    {
        $target = self::assertCurrency($targetCurrency);
        $converted = $this->multiplyByDecimal($rate);

        return new self($converted->amount, $target);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    /** Major-unit decimal string, e.g. "1500.25". Safe for display and APIs. */
    public function toDecimal(): string
    {
        $abs = abs($this->amount);
        $divisor = 10 ** self::MINOR_UNIT_EXPONENT;
        $sign = $this->amount < 0 ? '-' : '';

        return sprintf(
            '%s%d.%0'.self::MINOR_UNIT_EXPONENT.'d',
            $sign, intdiv($abs, $divisor), $abs % $divisor
        );
    }

    /** "₦1,500,000.25" — used in PDFs and mail where JS Intl is unavailable. */
    public function format(): string
    {
        $abs = abs($this->amount);
        $divisor = 10 ** self::MINOR_UNIT_EXPONENT;
        $whole = number_format(intdiv($abs, $divisor));
        $fraction = sprintf('%0'.self::MINOR_UNIT_EXPONENT.'d', $abs % $divisor);
        $symbol = self::SYMBOLS[$this->currency] ?? $this->currency.' ';

        return ($this->amount < 0 ? '-' : '').$symbol.$whole.'.'.$fraction;
    }

    public function equals(self $other): bool
    {
        return $this->currency === $other->currency && $this->amount === $other->amount;
    }

    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount,
            'currency' => $this->currency,
            'formatted' => $this->format(),
        ];
    }

    private static function assertCurrency(string $currency): string
    {
        $currency = strtoupper($currency);

        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("'{$currency}' is not an ISO-4217 currency code.");
        }

        return $currency;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot combine {$this->currency} with {$other->currency}: convert explicitly first."
            );
        }
    }

    private static function roundHalfUp(string $decimal): int
    {
        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '-');
        [$whole, $fraction] = array_pad(explode('.', $decimal), 2, '');

        $value = (int) $whole;
        if ($fraction !== '' && (int) $fraction[0] >= 5) {
            $value++;
        }

        return $negative ? -$value : $value;
    }
}
