<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\Orders\InvalidOrderStatusException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Services\Catalog\CatalogCache;
use Illuminate\Support\Facades\DB;

/**
 * Cancels an order inside one transaction.
 *
 * The order row is locked first. Stock is restored only when the status actually
 * changes away from a cancellable state, so a second cancel request is a no-op
 * (HTTP 200, no extra inventory, no extra coupon credit).
 *
 * A cancelled order releases its promotional usage so the customer can use the
 * code again, up to the original limits.
 */
class CancelOrderService
{
    public function __construct(private readonly CatalogCache $catalogCache) {}

    public function cancel(Order $order): CancelOrderResult
    {
        $result = DB::transaction(function () use ($order) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::Cancelled) {
                return new CancelOrderResult($locked->loadMissing('items'), replayed: true);
            }

            if (! $locked->canBeCancelled()) {
                throw new InvalidOrderStatusException($locked->status);
            }

            $affected = Order::query()
                ->whereKey($locked->id)
                ->whereIn('status', array_map(fn (OrderStatus $status) => $status->value, OrderStatus::cancellable()))
                ->update([
                    'status' => OrderStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                $locked->refresh();

                if ($locked->status === OrderStatus::Cancelled) {
                    return new CancelOrderResult($locked->loadMissing('items'), replayed: true);
                }

                throw new InvalidOrderStatusException($locked->status);
            }

            $this->restoreStock($locked);
            $this->releasePromotion($locked);

            return new CancelOrderResult($locked->fresh()->loadMissing('items'));
        });

        if (! $result->replayed) {
            $this->catalogCache->bump();
        }

        return $result;
    }

    private function restoreStock(Order $order): void
    {
        $quantities = $order->items()
            ->whereNotNull('product_id')
            ->get()
            ->groupBy('product_id')
            ->map(fn ($items) => (int) $items->sum('quantity'));

        $productIds = $quantities->keys()->sort()->values();

        if ($productIds->isEmpty()) {
            return;
        }

        Product::withTrashed()
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($productIds as $productId) {
            Product::withTrashed()
                ->whereKey($productId)
                ->increment('stock_quantity', $quantities[$productId]);
        }
    }

    private function releasePromotion(Order $order): void
    {
        /** @var PromotionUsage|null $usage */
        $usage = PromotionUsage::query()
            ->where('order_id', $order->id)
            ->lockForUpdate()
            ->first();

        if ($usage === null) {
            return;
        }

        if ($usage->promotion_id !== null) {
            Promotion::withTrashed()
                ->whereKey($usage->promotion_id)
                ->where('used_count', '>', 0)
                ->decrement('used_count');
        }

        $usage->delete();
    }
}
