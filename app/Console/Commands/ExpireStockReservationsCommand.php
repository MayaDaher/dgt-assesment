<?php

namespace App\Console\Commands;

use App\Services\Inventory\StockReservationService;
use Illuminate\Console\Command;

class ExpireStockReservationsCommand extends Command
{
    protected $signature = 'cart:expire-reservations';

    protected $description = 'Delete expired cart stock reservations so the units return to the available pool.';

    public function handle(StockReservationService $reservations): int
    {
        $deleted = $reservations->purgeExpired();

        $this->info("Released {$deleted} expired stock reservation(s).");

        return self::SUCCESS;
    }
}
