<?php

namespace App\Services\Promotions;

use App\Enums\PromotionType;
use App\Models\Promotion;
use App\Services\Cart\CartLine;
use App\Support\Money;

/**
 * Pure discount math in integer cents.
 *
 * - percentage: eligible subtotal x value%, rounded half-up to the cent, then capped by max_discount_amount
 * - fixed: the fixed amount, never more than the eligible subtotal
 * - the discount never exceeds the eligible amount, so the order total can never be negative
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

    /**
     * Splits an order-level discount across the eligible lines. Remainder cents
     * land on the last eligible line so the parts always sum to the total.
     *
     * @param  list<CartLine>  $lines
     * @return list<CartLine>
     */
    public function applyToLines(Promotion $promotion, array $lines, int $discountCents): array
    {
        $eligibleTotals = [];

        foreach ($lines as $index => $line) {
            if ($line->isPurchasable() && $promotion->appliesTo($line->product)) {
                $eligibleTotals[$index] = $line->lineTotalCents;
            }
        }

        $shares = $this->shares($discountCents, $eligibleTotals);

        $priced = [];

        foreach ($lines as $index => $line) {
            $eligible = $line->isPurchasable() && $promotion->appliesTo($line->product);

            $priced[] = new CartLine(
                item: $line->item,
                product: $line->product,
                quantity: $line->quantity,
                unitPriceCents: $line->unitPriceCents,
                lineTotalCents: $line->lineTotalCents,
                availableQuantity: $line->availableQuantity,
                issue: $line->issue,
                promotionEligible: $eligible,
                discountCents: $shares[$index] ?? 0,
            );
        }

        return $priced;
    }

    /**
     * @param  array<int, int>  $eligibleTotals
     * @return array<int, int>
     */
    private function shares(int $discountCents, array $eligibleTotals): array
    {
        $sum = array_sum($eligibleTotals);

        if ($discountCents <= 0 || $sum <= 0) {
            return [];
        }

        $shares = [];
        $remaining = $discountCents;
        $last = array_key_last($eligibleTotals);

        foreach ($eligibleTotals as $index => $total) {
            if ($index === $last) {
                $shares[$index] = $remaining;
                break;
            }

            $share = intdiv($total * $discountCents, $sum);
            $shares[$index] = $share;
            $remaining -= $share;
        }

        return $shares;
    }
}
