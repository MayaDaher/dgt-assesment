<?php

namespace App\Http\Resources;

use App\Services\Cart\CartLine;
use App\Services\Cart\CartSummary;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property CartSummary $resource
 */
class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $summary = $this->resource;

        return [
            'id' => $summary->cart->id,
            'items' => array_map(fn (CartLine $line) => $this->line($line), $summary->lines),
            'items_count' => count($summary->lines),
            'total_quantity' => $summary->totalQuantity,
            'subtotal' => Money::format($summary->subtotalCents),
            'promotion' => $this->promotion($summary),
            'discount' => Money::format($summary->discountCents),
            'total' => Money::format($summary->totalCents()),
            'has_issues' => $summary->hasIssues(),
            'checkout_ready' => $summary->isCheckoutReady(),
            'updated_at' => $summary->cart->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function promotion(CartSummary $summary): ?array
    {
        if ($summary->promotion === null) {
            return null;
        }

        $error = $summary->promotionError;

        return [
            'code' => $summary->promotion->code,
            'description' => $summary->promotion->description,
            'type' => $summary->promotion->type->value,
            'value' => $summary->promotion->value,
            'is_valid' => $error === null,
            'error' => $error === null ? null : [
                'code' => $error->errorCode(),
                'message' => $error->getMessage(),
                'details' => $error->details() ?: null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(CartLine $line): array
    {
        return [
            'id' => $line->item->id,
            'product' => [
                'id' => $line->product->id,
                'name' => $line->product->name,
                'sku' => $line->product->sku,
                'price' => Money::format($line->unitPriceCents),
            ],
            'quantity' => $line->quantity,
            'unit_price' => Money::format($line->unitPriceCents),
            'line_total' => Money::format($line->lineTotalCents),
            'available_quantity' => $line->availableQuantity,
            'is_purchasable' => $line->isPurchasable(),
            'issue' => $line->issue?->value,
        ];
    }
}
