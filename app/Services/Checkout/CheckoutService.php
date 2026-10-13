<?php

namespace App\Services\Checkout;

use App\Enums\CartLineIssue;
use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Enums\ProductStatus;
use App\Enums\PromotionError;
use App\Events\OrderPlaced;
use App\Exceptions\Cart\CartNotReadyException;
use App\Exceptions\Cart\EmptyCartException;
use App\Exceptions\Catalog\ProductUnavailableException;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Exceptions\Promotion\PromotionException;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\User;
use App\Services\Cart\CartCalculator;
use App\Services\Cart\CartLine;
use App\Services\Cart\CartSummary;
use App\Services\Catalog\CatalogCache;
use App\Services\Inventory\StockReservationService;
use App\Services\Orders\OrderStatusRecorder;
use App\Services\Promotions\PromotionEligibility;
use App\Support\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Places an order from the customer's cart inside one database transaction.
 *
 * Prices, discounts and totals are always recalculated here. Concurrent checkouts
 * lock product rows in id order, then decrement stock only when enough remains
 * (`WHERE stock_quantity >= quantity`), so two buyers cannot push inventory below zero.
 *
 * Cart reservations are released after the decrement so the same units are not
 * subtracted twice.
 */
class CheckoutService
{
    public function __construct(
        private readonly CartCalculator $calculator,
        private readonly PromotionEligibility $eligibility,
        private readonly CatalogCache $catalogCache,
        private readonly StockReservationService $reservations,
        private readonly OrderStatusRecorder $statuses,
    ) {}

    public function place(
        Cart $cart,
        ShippingAddress $shipping,
        User $user,
        ?string $idempotencyKey = null,
    ): CheckoutResult {
        $key = filled($idempotencyKey) ? trim((string) $idempotencyKey) : null;
        $scope = 'u:'.$user->id;

        try {
            $result = DB::transaction(function () use ($cart, $shipping, $user, $key, $scope) {
                $locked = Cart::query()->whereKey($cart->id)->lockForUpdate()->first();

                $replayed = $this->existingOrder($scope, $key);

                if ($replayed !== null) {
                    return new CheckoutResult($replayed, replayed: true);
                }

                if ($locked === null || $locked->items()->doesntExist()) {
                    throw new EmptyCartException;
                }

                $summary = $this->summarizeLockedCart($locked, $user);
                $this->assertReady($summary);

                $this->decrementStock($summary);
                $this->reservations->release($locked);
                $this->consumePromotion($summary, $user);

                $order = $this->createOrder($summary, $shipping, $user, $key, $scope);
                $this->statuses->record(
                    $order,
                    from: null,
                    to: OrderStatus::Pending,
                    source: OrderStatusSource::Checkout,
                    actorId: $user->id,
                );

                $locked->items()->delete();
                $locked->update(['promotion_id' => null]);

                return new CheckoutResult($order->load(['items', 'statusChanges']));
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Two retries with the same key can both pass the SELECT and then collide on insert.
            // The failed transaction is rolled back (stock is not taken twice); return the winner.
            $replayed = $this->existingOrder($scope, $key);

            if ($replayed !== null && $this->isIdempotencyCollision($exception)) {
                return new CheckoutResult($replayed, replayed: true);
            }

            throw $exception;
        }

        if (! $result->replayed) {
            OrderPlaced::dispatch($result->order->id);
            $this->catalogCache->bump();
        }

        return $result;
    }

    private function existingOrder(?string $scope, ?string $key): ?Order
    {
        if ($scope === null || $key === null) {
            return null;
        }

        return Order::query()
            ->where('idempotency_scope', $scope)
            ->where('idempotency_key', $key)
            ->with(['items', 'statusChanges'])
            ->first();
    }

    private function isIdempotencyCollision(UniqueConstraintViolationException $exception): bool
    {
        return str_contains($exception->getMessage(), 'idempotency');
    }

    private function summarizeLockedCart(Cart $cart, User $user): CartSummary
    {
        $items = $cart->items()->orderBy('product_id')->get();
        $productIds = $items->pluck('product_id')->unique()->sort()->values();

        /** @var Collection<int, Product> $products */
        $products = Product::withTrashed()
            ->with('category')
            ->whereIn('id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            $product = $products->get($item->product_id);

            if ($product === null) {
                throw (new ModelNotFoundException)->setModel(Product::class, [$item->product_id]);
            }

            $item->setRelation('product', $product);
        }

        $cart->setRelation('items', $items);
        $cart->setRelation('user', $user);
        $cart->loadMissing('promotion');

        return $this->calculator->summarize($cart);
    }

    private function assertReady(CartSummary $summary): void
    {
        if ($summary->isEmpty()) {
            throw new EmptyCartException;
        }

        if ($summary->promotionError !== null) {
            throw $summary->promotionError;
        }

        $issues = [];

        foreach ($summary->lines as $line) {
            if ($line->isPurchasable()) {
                continue;
            }

            $issues[] = [
                'cart_item_id' => $line->item->id,
                'product_id' => $line->product->id,
                'issue' => $line->issue?->value,
                'requested_quantity' => $line->quantity,
                'available_quantity' => $line->availableQuantity,
            ];
        }

        if ($issues === []) {
            return;
        }

        if (count($issues) === 1) {
            $line = collect($summary->lines)->first(fn (CartLine $candidate) => ! $candidate->isPurchasable());

            throw match ($line?->issue) {
                CartLineIssue::ProductUnavailable => new ProductUnavailableException($line->product),
                CartLineIssue::InsufficientStock => new InsufficientStockException(
                    $line->product,
                    $line->quantity,
                    $line->availableQuantity,
                ),
                default => new CartNotReadyException($issues),
            };
        }

        throw new CartNotReadyException($issues);
    }

    private function decrementStock(CartSummary $summary): void
    {
        foreach ($summary->lines as $line) {
            $affected = Product::query()
                ->whereKey($line->product->id)
                ->where('status', ProductStatus::Active)
                ->where('stock_quantity', '>=', $line->quantity)
                ->decrement('stock_quantity', $line->quantity);

            if ($affected === 0) {
                $available = (int) Product::query()->whereKey($line->product->id)->value('stock_quantity');

                throw new InsufficientStockException($line->product, $line->quantity, $available);
            }
        }
    }

    private function consumePromotion(CartSummary $summary, User $user): void
    {
        if ($summary->promotion === null) {
            return;
        }

        $promotion = Promotion::query()->whereKey($summary->promotion->id)->lockForUpdate()->first();

        if ($promotion === null) {
            throw new PromotionException(PromotionError::Invalid);
        }

        $promotion->loadMissing(['products', 'categories.children']);
        $this->eligibility->check($promotion, $user, $summary->lines);

        $query = Promotion::query()->whereKey($promotion->id);

        if ($promotion->usage_limit !== null) {
            $query->whereColumn('used_count', '<', 'usage_limit');
        }

        if ($query->increment('used_count') === 0) {
            throw new PromotionException(PromotionError::UsageLimitReached);
        }
    }

    private function createOrder(
        CartSummary $summary,
        ShippingAddress $shipping,
        User $user,
        ?string $idempotencyKey,
        string $idempotencyScope,
    ): Order {
        $order = Order::query()->create([
            'user_id' => $user->id,
            'promotion_id' => $summary->promotion?->id,
            'promotion_code' => $summary->promotion?->code,
            'status' => OrderStatus::Pending,
            'subtotal' => Money::format($summary->subtotalCents),
            'discount_amount' => Money::format($summary->discountCents),
            'total' => Money::format($summary->totalCents()),
            'idempotency_key' => $idempotencyKey,
            'idempotency_scope' => $idempotencyKey !== null ? $idempotencyScope : null,
            ...$shipping->toOrderAttributes(),
        ]);

        foreach ($summary->lines as $line) {
            $order->items()->create([
                'product_id' => $line->product->id,
                'product_name' => $line->product->name,
                'product_sku' => $line->product->sku,
                'unit_price' => Money::format($line->unitPriceCents),
                'quantity' => $line->quantity,
                'line_total' => Money::format($line->lineTotalCents),
            ]);
        }

        if ($summary->promotion !== null) {
            PromotionUsage::query()->create([
                'promotion_id' => $summary->promotion->id,
                'user_id' => $user->id,
                'order_id' => $order->id,
                'used_at' => now(),
            ]);
        }

        return $order;
    }
}
