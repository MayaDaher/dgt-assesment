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
        $admin = $request->user()?->isAdmin() === true;

        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status->value,
            'email' => $this->when(
                $this->relationLoaded('user'),
                fn () => $this->user?->email,
            ),
            'shipping' => $this->shipping(),
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
            'payable' => $this->canBePaid(),
            'cancellable' => $this->canBeCancelled(),
            'allowed_statuses' => $this->when(
                $admin,
                fn () => array_map(
                    fn ($status) => $status->value,
                    $this->status->allowedAdminTransitions(),
                ),
            ),
            'status_history' => OrderStatusChangeResource::collection($this->whenLoaded('statusChanges')),
            'created_at' => $this->created_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'processing_at' => $this->processing_at?->toIso8601String(),
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
        ];
    }
}
