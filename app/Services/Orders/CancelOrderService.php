<?php

namespace App\Services\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Events\OrderStatusChanged;
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
 * code again, up to the original limits. Unpaid pending expiry uses this same
 * path so inventory cannot leak.
 */
class CancelOrderService
{
    public function __construct(
        private readonly CatalogCache $catalogCache,
        private readonly OrderStatusRecorder $recorder,
    ) {}

    public function cancel(
        Order $order,
        OrderStatusSource $source = OrderStatusSource::Cancel,
        ?int $actorId = null,
    ): CancelOrderResult {
        $from = null;

        $result = DB::transaction(function () use ($order, $source, $actorId, &$from) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::Cancelled) {
                return new CancelOrderResult($locked->loadMissing(['items', 'statusChanges']), replayed: true);
            }

            $allowed = $this->cancellableFrom($source);

            if (! in_array($locked->status, $allowed, true)) {
                if ($source === OrderStatusSource::Expiry) {
                    return new CancelOrderResult($locked->loadMissing(['items', 'statusChanges']), replayed: true);
                }

                throw new InvalidOrderStatusException($locked->status);
            }

            $from = $locked->status;

            $affected = Order::query()
                ->whereKey($locked->id)
                ->whereIn('status', array_map(fn (OrderStatus $status) => $status->value, $allowed))
                ->update([
                    'status' => OrderStatus::Cancelled->value,
                    'cancelled_at' => now(),
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                $locked->refresh();

                if ($locked->status === OrderStatus::Cancelled || $source === OrderStatusSource::Expiry) {
                    return new CancelOrderResult($locked->loadMissing(['items', 'statusChanges']), replayed: true);
                }

                throw new InvalidOrderStatusException($locked->status);
            }

            $this->restoreStock($locked);
            $this->releasePromotion($locked);

            $fresh = $locked->fresh()->loadMissing(['items', 'statusChanges']);
            $this->recorder->record(
                $fresh,
                $from,
                OrderStatus::Cancelled,
                $source,
                $source === OrderStatusSource::Expiry ? null : ($actorId ?? $fresh->user_id),
            );

            return new CancelOrderResult($fresh->load('statusChanges'));
        });

        if (! $result->replayed) {
            $this->catalogCache->bump();
            OrderStatusChanged::dispatch(
                $result->order->id,
                $from,
                OrderStatus::Cancelled,
                $source,
            );
        }

        return $result;
    }

    /**
     * System timeout for unpaid pending orders. No-op if the customer already paid or cancelled.
     */
    public function expireUnpaid(Order $order): CancelOrderResult
    {
        return $this->cancel($order, OrderStatusSource::Expiry);
    }

    /**
     * @return list<OrderStatus>
     */
    private function cancellableFrom(OrderStatusSource $source): array
    {
        return $source === OrderStatusSource::Expiry
            ? [OrderStatus::Pending]
            : OrderStatus::cancellable();
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
