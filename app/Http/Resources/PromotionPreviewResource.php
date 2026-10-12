<?php

namespace App\Http\Resources;

use App\Services\Promotions\PromotionPreview;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property PromotionPreview $resource
 */
class PromotionPreviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $promotion = $this->resource->promotion;

        return [
            'eligible' => true,
            'code' => $promotion->code,
            'description' => $promotion->description,
            'type' => $promotion->type->value,
            'scope' => $promotion->scope->value,
            'value' => $promotion->value,
            'min_cart_amount' => $promotion->min_cart_amount,
            'max_discount_amount' => $promotion->max_discount_amount,
            'ends_at' => $promotion->ends_at?->toIso8601String(),
            'eligible_subtotal' => Money::format($this->resource->eligibleSubtotalCents),
            'subtotal' => Money::format($this->resource->subtotalCents),
            'discount' => Money::format($this->resource->discountCents),
            'total' => Money::format($this->resource->totalCents()),
        ];
    }
}
