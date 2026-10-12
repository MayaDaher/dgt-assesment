<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Fulfilment hook for a placed order. Queued so checkout stays fast under load.
 *
 * Replace the log with mail, stock-reservation exports, or a warehouse push
 * without changing CheckoutService.
 */
class SendOrderPlacedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public function handle(OrderPlaced $event): void
    {
        Log::info('Order placed.', [
            'order_id' => $event->orderId,
            'order_number' => $event->order()->order_number,
        ]);
    }
}
