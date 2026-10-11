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
            'decimal string' => ['19.99', 1999],
            'one decimal' => ['19.5', 1950],
            'no decimals' => ['20', 2000],
            'integer' => [7, 700],
            'float' => [0.1 + 0.2, 30],
            'zero' => ['0.00', 0],
            'negative' => ['-5.25', -525],
            'large' => ['9999999999.99', 999999999999],
        ];
    }

    #[DataProvider('amounts')]
    public function test_to_cents(string|int|float $amount, int $expected): void
    {
        $this->assertSame($expected, Money::toCents($amount));
    }

    public function test_format(): void
    {
        $this->assertSame('19.99', Money::format(1999));
        $this->assertSame('0.05', Money::format(5));
        $this->assertSame('-5.25', Money::format(-525));
        $this->assertSame('0.00', Money::format(0));
    }

    public function test_round_trip_has_no_float_drift(): void
    {
        $total = 0;

        for ($i = 0; $i < 10; $i++) {
            $total += Money::toCents('0.10');
        }

        $this->assertSame('1.00', Money::format($total));
    }

    public function test_invalid_amount_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::toCents('12abc');
    }
}
