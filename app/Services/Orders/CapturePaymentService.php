<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Events\OrderStatusChanged;
use App\Exceptions\Orders\OrderNotPayableException;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

/**
 * Confirms payment on a pending order (no card processor — assessment stub).
 *
 * Inventory was already committed at checkout. This step only moves pending → paid
 * so fulfilment can start. A second capture is a no-op (HTTP 200).
 */
class CapturePaymentService
{
    public function __construct(private readonly OrderStatusRecorder $recorder) {}

    public function capture(Order $order, int $actorId): OrderMutationResult
    {
        $from = null;

        $result = DB::transaction(function () use ($order, $actorId, &$from) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::Paid) {
                return new OrderMutationResult($locked->loadMissing(['items', 'statusChanges']), replayed: true);
            }

            if ($locked->status !== OrderStatus::Pending) {
                throw new OrderNotPayableException($locked->status);
            }

            $from = $locked->status;
            $this->recorder->transition($locked, OrderStatus::Paid, OrderStatusSource::Payment, $actorId);

            return new OrderMutationResult($locked->fresh()->loadMissing(['items', 'statusChanges']));
        });

        if (! $result->replayed && $from !== null) {
            OrderStatusChanged::dispatch($result->order->id, $from, OrderStatus::Paid, OrderStatusSource::Payment);
        }

        return $result;
    }
}
