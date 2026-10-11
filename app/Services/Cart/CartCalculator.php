<?php

namespace App\Services\Cart;

use App\Enums\CartLineIssue;
use App\Models\Cart;
use App\Models\CartItem;
use App\Support\Money;

/**
 * Prices every line from the current product data (never from the client).
 * The subtotal only counts lines that can be bought as they are; lines with an issue
 * are still returned so the customer can see and fix them before checkout.
 */
class CartCalculator
{
    public function summarize(Cart $cart): CartSummary
    {
        $cart->loadMissing('items.product');

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

        return new CartSummary($cart, $lines, $subtotal, $totalQuantity);
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
