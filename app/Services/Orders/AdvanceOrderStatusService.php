<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\Orders\InvalidOrderTransitionException;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Moves an order one step along the fulfilment path inside a row lock.
 *
 * Concurrent PATCHes serialize on the order row: the loser re-reads the new
 * status and is rejected if the requested transition is no longer legal.
 */
class AdvanceOrderStatusService
{
    public function advance(Order $order, OrderStatus $next): Order
    {
        return DB::transaction(function () use ($order, $next) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($next)) {
                throw new InvalidOrderTransitionException($locked->status, $next);
            }

            $locked->update(['status' => $next]);

            return $locked->fresh()->loadMissing(['items', 'user']);
        });
    }
}
