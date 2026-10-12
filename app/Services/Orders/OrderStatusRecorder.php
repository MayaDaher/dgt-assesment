<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Models\Order;
use App\Models\OrderStatusChange;

/**
 * Writes the immutable status history and stamps the matching occurred-at column.
 *
 * Callers must already hold the order row lock inside a transaction.
 */
class OrderStatusRecorder
{
    public function transition(
        Order $order,
        OrderStatus $to,
        OrderStatusSource $source,
        ?int $actorId = null,
    ): OrderStatusChange {
        $from = $order->status;
        $attributes = ['status' => $to];
        $column = $to->occurredAtColumn();

        if ($column !== null && $order->{$column} === null) {
            $attributes[$column] = now();
        }

        $order->update($attributes);

        return $this->record($order, $from, $to, $source, $actorId);
    }

    public function record(
        Order $order,
        ?OrderStatus $from,
        OrderStatus $to,
        OrderStatusSource $source,
        ?int $actorId = null,
    ): OrderStatusChange {
        return OrderStatusChange::query()->create([
            'order_id' => $order->id,
            'from_status' => $from,
            'to_status' => $to,
            'source' => $source,
            'user_id' => $actorId,
        ]);
    }
}
