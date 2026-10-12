<?php

namespace App\Services\Promotions;

use App\Models\Promotion;

final readonly class PromotionPreview
{
    public function __construct(
        public Promotion $promotion,
        public int $subtotalCents,
        public int $discountCents,
        public int $eligibleSubtotalCents,
    ) {}

    public function totalCents(): int
    {
        return $this->subtotalCents - $this->discountCents;
    }
}
