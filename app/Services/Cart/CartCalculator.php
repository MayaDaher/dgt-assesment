<?php

namespace App\Services\Cart;

use App\Enums\CartLineIssue;
use App\Exceptions\Promotion\PromotionException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\StockReservation;
use App\Services\Inventory\StockReservationService;
use App\Services\Promotions\DiscountCalculator;
use App\Services\Promotions\PromotionEligibility;
use App\Support\Money;
use Illuminate\Support\Carbon;

/**
 * Prices every line from the current product data (never from the client).
 * The subtotal only counts lines that can be bought as they are; lines with an issue
 * are still returned so the customer can see and fix them before checkout.
 *
 * Available quantity is on-hand stock minus other carts' unexpired reservations.
 */
class CartCalculator
{
    public function __construct(
        private readonly PromotionEligibility $eligibility,
        private readonly StockReservationService $reservations,
        private readonly DiscountCalculator $discounts,
    ) {}

    /**
     * The applied promotion is re-validated on every call: if it expired or the cart no longer
     * meets its minimum, the discount drops to zero and the error is returned with the summary.
     */
    public function summarize(Cart $cart, bool $withPromotion = true): CartSummary
    {
        $cart->loadMissing([
            'items.product.category',
            'promotion.products',
            'promotion.categories.children',
            'user',
            'reservations',
        ]);

        $lines = $cart->items
            ->sortBy('id')
            ->map(fn (CartItem $item) => $this->line($cart, $item))
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
                $discount = $this->eligibility->check($promotion, $cart->user, $lines);
                $lines = $this->discounts->applyToLines($promotion, $lines, $discount);
            } catch (PromotionException $e) {
                $promotionError = $e;
            }
        }

        return new CartSummary(
            cart: $cart,
            lines: $lines,
            subtotalCents: $subtotal,
            totalQuantity: $totalQuantity,
            promotion: $promotion,
            discountCents: $discount,
            promotionError: $promotionError,
            reservationExpiresAt: $this->reservationExpiresAt($cart),
        );
    }

    private function line(Cart $cart, CartItem $item): CartLine
    {
        $product = $item->product;
        $unitPrice = Money::toCents($product->price);
        $available = $this->reservations->availableFor($product, $cart);

        $issue = match (true) {
            $product->trashed() || ! $product->isActive() => CartLineIssue::ProductUnavailable,
            $item->quantity > $available => CartLineIssue::InsufficientStock,
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

    private function reservationExpiresAt(Cart $cart): ?Carbon
    {
        /** @var StockReservation|null $soonest */
        $soonest = $cart->reservations
            ->filter(fn (StockReservation $reservation) => $reservation->isActive())
            ->sortBy('expires_at')
            ->first();

        return $soonest?->expires_at;
    }
}
