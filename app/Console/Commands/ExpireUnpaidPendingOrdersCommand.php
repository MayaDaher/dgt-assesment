<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\Orders\CancelOrderService;
use Illuminate\Console\Command;

class ExpireUnpaidPendingOrdersCommand extends Command
{
    protected $signature = 'orders:expire-pending';

    protected $description = 'Cancel unpaid pending orders past their payment window and restore stock.';

    public function handle(CancelOrderService $canceller): int
    {
        $ids = Order::query()
            ->unpaidExpired()
            ->orderBy('id')
            ->pluck('id');

        $expired = 0;

        foreach ($ids as $id) {
            $order = Order::query()->find($id);

            if ($order === null) {
                continue;
            }

            $result = $canceller->expireUnpaid($order);

            if (! $result->replayed) {
                $expired++;
            }
        }

        $this->info("Expired {$expired} unpaid pending order(s).");

        return self::SUCCESS;
    }
}
