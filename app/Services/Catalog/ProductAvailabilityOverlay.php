<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\StockReservation;
use Illuminate\Support\Collection;

/**
 * Attaches live `available_quantity` (on-hand minus unexpired holds) to catalog
 * products without putting reservation state into the listing cache.
 */
class ProductAvailabilityOverlay
{
    /**
     * @param  iterable<Product|null>  $products
     */
    public function apply(iterable $products): void
    {
        $items = Collection::make($products)->filter(fn ($product) => $product instanceof Product);

        if ($items->isEmpty()) {
            return;
        }

        $held = StockReservation::query()
            ->active()
            ->whereIn('product_id', $items->pluck('id'))
            ->selectRaw('product_id, SUM(quantity) as held')
            ->groupBy('product_id')
            ->pluck('held', 'product_id');

        foreach ($items as $product) {
            $available = max(0, (int) $product->stock_quantity - (int) $held->get($product->id, 0));
            $product->setAttribute('available_quantity', $available);
        }
    }

    public function one(Product $product): Product
    {
        $this->apply([$product]);

        return $product;
    }
}
