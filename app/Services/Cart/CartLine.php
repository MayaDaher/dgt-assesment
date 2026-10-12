<?php

namespace App\Services\Cart;

use App\Enums\CartLineIssue;
use App\Models\CartItem;
use App\Models\Product;

final readonly class CartLine
{
    public function __construct(
        public CartItem $item,
        public Product $product,
        public int $quantity,
        public int $unitPriceCents,
        public int $lineTotalCents,
        public int $availableQuantity,
        public ?CartLineIssue $issue,
        public bool $promotionEligible = false,
        public int $discountCents = 0,
    ) {}

    public function isPurchasable(): bool
    {
        return $this->issue === null;
    }
}
