<?php

namespace App\Services\Cart;

use App\Exceptions\Promotion\PromotionException;
use App\Models\Cart;
use App\Models\Promotion;
use Illuminate\Support\Carbon;

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
        public ?Carbon $reservationExpiresAt = null,
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
        return $this->checkoutBlocker() === null;
    }

    /**
     * Stable machine code a client can switch on when checkout_ready is false.
     */
    public function checkoutBlocker(): ?string
    {
        if ($this->isEmpty()) {
            return 'CART_EMPTY';
        }

        if ($this->promotionError !== null) {
            return $this->promotionError->errorCode();
        }

        $issues = [];

        foreach ($this->lines as $line) {
            if ($line->issue !== null) {
                $issues[$line->issue->errorCode()] = true;
            }
        }

        $codes = array_keys($issues);

        return match (count($codes)) {
            0 => null,
            1 => $codes[0],
            default => 'CART_HAS_ISSUES',
        };
    }
}
