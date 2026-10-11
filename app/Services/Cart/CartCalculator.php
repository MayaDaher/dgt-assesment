<?php

namespace App\Services\Cart;

use App\Enums\CartLineIssue;
use App\Exceptions\Promotion\PromotionException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Services\Promotions\PromotionEligibility;
use App\Support\Money;

/**
 * Prices every line from the current product data (never from the client).
 * The subtotal only counts lines that can be bought as they are; lines with an issue
 * are still returned so the customer can see and fix them before checkout.
 */
class CartCalculator
{
    public function __construct(private readonly PromotionEligibility $eligibility) {}

    /**
     * The applied promotion is re-validated on every call: if it expired or the cart no longer
     * meets its minimum, the discount drops to zero and the error is returned with the summary.
     */
    public function summarize(Cart $cart, bool $withPromotion = true): CartSummary
    {
        $cart->loadMissing(['items.product', 'promotion', 'user']);

        $lines = $cart->items
            ->sortBy('id')
            ->map(fn (CartItem $item) => $this->line($item))
            ->values()
            ->all();

        $subtotal = 0;
        $totalQuantity = 0;

        foreach ($lines as $line) {
            $totalQuantity += $line->quantity;

            if ($line->isPurchasable()) {
                $subtotal += $line->lineTotalCents;
            }
        }

        $promotion = $withPromotion ? $cart->promotion : null;
        $discount = 0;
        $promotionError = null;

        if ($promotion !== null) {
            try {
                $discount = $this->eligibility->check($promotion, $cart->user, $subtotal);
            } catch (PromotionException $e) {
                $promotionError = $e;
            }
        }

        return new CartSummary($cart, $lines, $subtotal, $totalQuantity, $promotion, $discount, $promotionError);
    }

    private function line(CartItem $item): CartLine
    {
        $product = $item->product;
        $unitPrice = Money::toCents($product->price);
        $available = $product->trashed() || ! $product->isActive() ? 0 : $product->stock_quantity;

        $issue = match (true) {
            $product->trashed() || ! $product->isActive() => CartLineIssue::ProductUnavailable,
            $item->quantity > $product->stock_quantity => CartLineIssue::InsufficientStock,
            default => null,
        };

        return new CartLine(
            item: $item,
            product: $product,
            quantity: $item->quantity,
            unitPriceCents: $unitPrice,
            lineTotalCents: $unitPrice * $item->quantity,
            availableQuantity: $available,
            issue: $issue,
        );
    }
}
