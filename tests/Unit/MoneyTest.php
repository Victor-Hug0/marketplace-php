<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    /**
     * @return array<string, array{string|int|float, int}>
     */
    public static function amounts(): array
    {
        return [
            'string with cents' => ['1999.90', 199990],
            'string with one decimal' => ['19.9', 1990],
            'string without decimals' => ['150', 15000],
            'integer' => [150, 15000],
            'float that is inexact in binary' => [19.99, 1999],
            'float 0.1' => [0.1, 10],
            'float 1.01' => [1.01, 101],
            'large amount' => ['99999999.99', 9999999999],
        ];
    }

    #[DataProvider('amounts')]
    public function test_converts_brl_amounts_to_cents(string|int|float $amount, int $cents): void
    {
        $this->assertSame($cents, Money::toCents($amount));
    }

    public function test_formats_cents_as_brl_decimal_string(): void
    {
        $this->assertSame('1999.90', Money::format(199990));
        $this->assertSame('0.05', Money::format(5));
        $this->assertSame('0.00', Money::format(0));
        $this->assertSame('-27.00', Money::format(-2700));
    }

    public function test_rejects_more_than_two_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toCents('10.999');
    }

    public function test_rejects_non_numeric_input(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toCents('R$ 10,00');
    }
}
