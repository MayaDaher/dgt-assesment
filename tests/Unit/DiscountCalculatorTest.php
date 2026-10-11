<?php

namespace Tests\Unit;

use App\Enums\PromotionType;
use App\Models\Promotion;
use App\Services\Promotions\DiscountCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DiscountCalculatorTest extends TestCase
{
    private function promotion(PromotionType $type, string $value, ?string $maxDiscount = null): Promotion
    {
        return new Promotion([
            'code' => 'TEST',
            'type' => $type,
            'value' => $value,
            'max_discount_amount' => $maxDiscount,
        ]);
    }

    /**
     * @return array<string, array{PromotionType, string, ?string, int, int}>
     */
    public static function cases(): array
    {
        return [
            // The SUMMER20 example: 20%, max $50.
            'summer20 on $100.00' => [PromotionType::Percentage, '20.00', '50.00', 10000, 2000],
            'summer20 on $200.00 is below the cap' => [PromotionType::Percentage, '20.00', '50.00', 20000, 4000],
            'summer20 on $250.00 hits the cap exactly' => [PromotionType::Percentage, '20.00', '50.00', 25000, 5000],
            'summer20 on $300.00 is capped at $50' => [PromotionType::Percentage, '20.00', '50.00', 30000, 5000],
            'percentage without cap' => [PromotionType::Percentage, '50.00', null, 123456, 61728],
            'fractional percentage' => [PromotionType::Percentage, '12.50', null, 10000, 1250],
            'rounds half up (10% of 0.05 = 0.005)' => [PromotionType::Percentage, '10.00', null, 5, 1],
            'rounds down below half (12.5% of 0.99 = 0.12375)' => [PromotionType::Percentage, '12.50', null, 99, 12],
            '100% gives the whole subtotal' => [PromotionType::Percentage, '100.00', null, 4999, 4999],
            'fixed amount' => [PromotionType::Fixed, '10.00', null, 5000, 1000],
            'fixed never exceeds subtotal' => [PromotionType::Fixed, '100.00', null, 3000, 3000],
            'fixed respects max discount' => [PromotionType::Fixed, '100.00', '25.00', 50000, 2500],
            'zero subtotal gives zero discount' => [PromotionType::Percentage, '20.00', null, 0, 0],
        ];
    }

    #[DataProvider('cases')]
    public function test_discount(PromotionType $type, string $value, ?string $max, int $subtotalCents, int $expectedCents): void
    {
        $discount = (new DiscountCalculator)->discountFor($this->promotion($type, $value, $max), $subtotalCents);

        $this->assertSame($expectedCents, $discount);
    }
}
