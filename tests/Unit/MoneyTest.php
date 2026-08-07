<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_constructs_from_integer_minor_units(): void
    {
        $money = Money::of(150025, 'NGN');

        $this->assertSame(150025, $money->amount);
        $this->assertSame('NGN', $money->currency);
        $this->assertSame('1500.25', $money->toDecimal());
    }

    public function test_rejects_float_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(1500.25, 'NGN');
    }

    public function test_rejects_string_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of('1500', 'NGN');
    }

    public function test_rejects_invalid_currency_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(100, 'NAIRA');
    }

    public function test_parses_major_unit_decimal_strings_without_floats(): void
    {
        $this->assertSame(150025, Money::fromMajor('1500.25', 'NGN')->amount);
        $this->assertSame(150000, Money::fromMajor('1500', 'NGN')->amount);
        $this->assertSame(150050, Money::fromMajor('1500.5', 'NGN')->amount);
        $this->assertSame(-980, Money::fromMajor('-9.80', 'USD')->amount);
    }

    public function test_adds_and_subtracts_same_currency(): void
    {
        $a = Money::of(100000, 'NGN');
        $b = Money::of(25050, 'NGN');

        $this->assertSame(125050, $a->add($b)->amount);
        $this->assertSame(74950, $a->subtract($b)->amount);
    }

    public function test_rejects_cross_currency_addition(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(100, 'NGN')->add(Money::of(100, 'USD'));
    }

    public function test_multiplies_by_integer_factor(): void
    {
        $this->assertSame(500000, Money::of(100000, 'NGN')->multiply(5)->amount);
    }

    public function test_multiplies_by_decimal_string_with_half_up_rounding(): void
    {
        // ₦1,000.00 × 0.025 = ₦25.00
        $this->assertSame(2500, Money::of(100000, 'NGN')->multiplyByDecimal('0.025')->amount);
        // 999 minor units × 0.5 = 499.5 → 500 (half-up)
        $this->assertSame(500, Money::of(999, 'NGN')->multiplyByDecimal('0.5')->amount);
    }

    public function test_converts_between_currencies_at_a_decimal_rate(): void
    {
        // $100.00 at 1500 NGN/USD → ₦150,000.00
        $converted = Money::of(10000, 'USD')->convert('NGN', '1500');

        $this->assertSame('NGN', $converted->currency);
        $this->assertSame(15000000, $converted->amount);
    }

    public function test_formats_with_currency_symbol(): void
    {
        $this->assertSame('₦1,500,000.25', Money::of(150000025, 'NGN')->format());
        $this->assertSame('-$9.80', Money::of(-980, 'USD')->format());
    }

    public function test_json_serialises_as_amount_currency_formatted(): void
    {
        $json = Money::of(150025, 'NGN')->jsonSerialize();

        $this->assertSame(['amount' => 150025, 'currency' => 'NGN', 'formatted' => '₦1,500.25'], $json);
    }
}
