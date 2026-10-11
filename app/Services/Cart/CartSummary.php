<?php

namespace App\Services\Cart;

use App\Exceptions\Promotion\PromotionException;
use App\Models\Cart;
use App\Models\Promotion;

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
        public ?Promotion $promotion = null,
        public int $discountCents = 0,
        public ?PromotionException $promotionError = null,
    ) {}

    public function totalCents(): int
    {
        return $this->subtotalCents - $this->discountCents;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    public function hasIssues(): bool
    {
        if ($this->promotionError !== null) {
            return true;
        }

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
