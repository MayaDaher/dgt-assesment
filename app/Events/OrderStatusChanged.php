<?php

namespace App\Events;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised after an order actually changes status. Idempotent pay/cancel replays do not fire it.
 */
class OrderStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $orderId,
        public readonly ?OrderStatus $from,
        public readonly OrderStatus $to,
        public readonly OrderStatusSource $source,
    ) {}

    public function order(): Order
    {
        return Order::query()->with(['items', 'statusChanges'])->findOrFail($this->orderId);
    }
}
