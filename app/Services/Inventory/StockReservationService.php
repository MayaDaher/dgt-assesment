<?php

namespace App\Services\Inventory;

use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Cart;
use App\Models\Product;
use App\Models\StockReservation;
use Illuminate\Support\Carbon;

/**
 * Holds on-hand stock for a cart until checkout or TTL expiry.
 *
 * On-hand `products.stock_quantity` is unchanged until checkout. Available
 * quantity is on-hand minus other carts' unexpired reservations. This cart's
 * own hold is excluded so the customer can keep or reduce what they already have.
 */
class StockReservationService
{
    public function ttlMinutes(): int
    {
        return max(1, (int) config('cart.reservation_ttl_minutes', 15));
    }

    public function expiresAt(): Carbon
    {
        return now()->addMinutes($this->ttlMinutes());
    }

    /**
     * Units this cart may still claim, including units it already holds.
     */
    public function availableFor(Product $product, ?Cart $cart = null): int
    {
        if ($product->trashed() || ! $product->isActive()) {
            return 0;
        }

        $heldByOthers = (int) StockReservation::query()
            ->active()
            ->where('product_id', $product->id)
            ->when($cart !== null, fn ($query) => $query->where('cart_id', '!=', $cart->id))
            ->sum('quantity');

        return max(0, $product->stock_quantity - $heldByOthers);
    }

    /**
     * Replace this cart's hold for the product. Call with the product row locked.
     *
     * @throws InsufficientStockException
     */
    public function reserve(Cart $cart, Product $product, int $quantity): void
    {
        if ($quantity <= 0) {
            $this->releaseProduct($cart, $product);

            return;
        }

        $available = $this->availableFor($product, $cart);

        if ($quantity > $available) {
            throw new InsufficientStockException($product, $quantity, $available);
        }

        StockReservation::query()->updateOrCreate(
            [
                'cart_id' => $cart->id,
                'product_id' => $product->id,
            ],
            [
                'quantity' => $quantity,
                'expires_at' => $this->expiresAt(),
            ],
        );
    }

    /**
     * Re-acquire as much of each line as is still free. Does not throw: leftover
     * quantity is reported as `insufficient_stock` on the cart summary.
     */
    public function sync(Cart $cart): void
    {
        $this->purgeExpired();

        $cart->loadMissing('items.product');

        $kept = [];

        foreach ($cart->items as $item) {
            $product = $item->product;

            if ($product === null || $product->trashed() || ! $product->isActive()) {
                StockReservation::query()
                    ->where('cart_id', $cart->id)
                    ->where('product_id', $item->product_id)
                    ->delete();

                continue;
            }

            $hold = min($item->quantity, $this->availableFor($product, $cart));

            if ($hold <= 0) {
                $this->releaseProduct($cart, $product);

                continue;
            }

            StockReservation::query()->updateOrCreate(
                [
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                ],
                [
                    'quantity' => $hold,
                    'expires_at' => $this->expiresAt(),
                ],
            );

            $kept[] = $product->id;
        }

        $stale = StockReservation::query()->where('cart_id', $cart->id);

        if ($kept !== []) {
            $stale->whereNotIn('product_id', $kept);
        }

        $stale->delete();
    }

    public function release(Cart $cart): void
    {
        StockReservation::query()->where('cart_id', $cart->id)->delete();
    }

    public function releaseProduct(Cart $cart, Product $product): void
    {
        $this->releaseProductId($cart, $product->id);
    }

    public function releaseProductId(Cart $cart, int $productId): void
    {
        StockReservation::query()
            ->where('cart_id', $cart->id)
            ->where('product_id', $productId)
            ->delete();
    }

    public function purgeExpired(): int
    {
        return StockReservation::query()->where('expires_at', '<=', now())->delete();
    }
}
