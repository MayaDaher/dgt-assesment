<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Raised after a new order has been committed. Idempotent checkout replays do not fire it.
 */
class OrderPlaced
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly int $orderId) {}

    public function order(): Order
    {
        return Order::query()->with('items')->findOrFail($this->orderId);
    }
}
