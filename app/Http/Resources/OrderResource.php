<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Order
 */
class OrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'customer' => $this->when(
                $this->relationLoaded('user'),
                fn () => $this->user === null ? null : new UserResource($this->user),
            ),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenLoaded('items', fn () => $this->items->count()),
            'subtotal' => $this->subtotal,
            'discount' => $this->discount_amount,
            'total' => $this->total,
            'promotion' => $this->promotion_code === null ? null : [
                'id' => $this->promotion_id,
                'code' => $this->promotion_code,
            ],
            'cancellable' => $this->canBeCancelled(),
            'created_at' => $this->created_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
