<?php

namespace App\Services\Cart;

use App\Models\Cart;

final readonly class CartSummary
{
    /**
     * @param  list<CartLine>  $lines
     */
    public function __construct(
        public Cart $cart,
        public array $lines,
        public int $subtotalCents,
        public int $totalQuantity,
    ) {}

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function hasIssues(): bool
    {
        foreach ($this->lines as $line) {
            if (! $line->isPurchasable()) {
                return true;
            }
        }

        return false;
    }

    public function isCheckoutReady(): bool
    {
        return ! $this->isEmpty() && ! $this->hasIssues();
    }
}
