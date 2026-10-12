<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Events\OrderStatusChanged;
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
    public function __construct(private readonly OrderStatusRecorder $recorder) {}

    public function advance(Order $order, OrderStatus $next, ?int $actorId = null): Order
    {
        $from = null;

        $updated = DB::transaction(function () use ($order, $next, $actorId, &$from) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->canTransitionTo($next)) {
                throw new InvalidOrderTransitionException($locked->status, $next);
            }

            $from = $locked->status;
            $this->recorder->transition($locked, $next, OrderStatusSource::Admin, $actorId);

            return $locked->fresh()->loadMissing(['items', 'user', 'statusChanges']);
        });

        if ($from !== null) {
            OrderStatusChanged::dispatch($updated->id, $from, $next, OrderStatusSource::Admin);
        }

        return $updated;
    }
}
