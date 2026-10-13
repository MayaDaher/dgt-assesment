<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Fulfilment hook for a real status change (pay, ship, deliver, cancel, expiry).
 *
 * Queued so pay/admin PATCH stay fast. Replace the log with mail or a warehouse
 * push later without changing CapturePaymentService, AdvanceOrderStatusService,
 * or CancelOrderService. Idempotent replays never reach this listener.
 */
class LogOrderStatusChanged implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order();

        Log::info('Order status changed.', [
            'order_id' => $event->orderId,
            'order_number' => $order->order_number,
            'from_status' => $event->from?->value,
            'to_status' => $event->to->value,
            'source' => $event->source->value,
        ]);
    }
}
