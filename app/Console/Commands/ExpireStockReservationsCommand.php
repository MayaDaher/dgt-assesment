<?php

namespace App\Console\Commands;

use App\Models\Cart;
use App\Services\Inventory\StockReservationService;
use Illuminate\Console\Command;

class ExpireStockReservationsCommand extends Command
{
    protected $signature = 'cart:expire-reservations';

    protected $description = 'Release expired stock holds and delete idle guest carts.';

    public function handle(StockReservationService $reservations): int
    {
        $deleted = $reservations->purgeExpired();
        $pruned = $this->pruneIdleGuestCarts();

        $this->info("Released {$deleted} expired stock reservation(s).");
        $this->info("Pruned {$pruned} idle guest cart(s).");

        return self::SUCCESS;
    }

    private function pruneIdleGuestCarts(): int
    {
        $days = max(1, (int) config('cart.idle_days', 30));
        $ids = Cart::query()
            ->whereNull('user_id')
            ->where('updated_at', '<=', now()->subDays($days))
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            Cart::query()->find($id)?->discard();
        }

        return $ids->count();
    }
}
