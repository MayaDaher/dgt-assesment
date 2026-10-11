<?php

namespace App\Services\Promotions;

use App\Enums\PromotionType;
use App\Models\Promotion;
use App\Support\Money;

/**
 * Pure discount math in integer cents.
 *
 * - percentage: subtotal x value%, rounded half-up to the cent, then capped by max_discount_amount
 * - fixed: the fixed amount
 * - the discount never exceeds the subtotal, so the total can never be negative
 */
class DiscountCalculator
{
    public function discountFor(Promotion $promotion, int $subtotalCents): int
    {
        if ($subtotalCents <= 0) {
            return 0;
        }

        $discount = match ($promotion->type) {
            // value "12.50" -> 1250 hundredths of a percent; divide by 100% = 10000.
            PromotionType::Percentage => intdiv($subtotalCents * Money::toCents($promotion->value) + 5000, 10000),
            PromotionType::Fixed => Money::toCents($promotion->value),
        };

        if ($promotion->max_discount_amount !== null) {
            $discount = min($discount, Money::toCents($promotion->max_discount_amount));
        }

        return max(0, min($discount, $subtotalCents));
    }
}
