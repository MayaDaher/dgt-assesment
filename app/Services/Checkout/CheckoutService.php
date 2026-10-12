<?php

namespace App\Services\Checkout;

use App\Enums\CartLineIssue;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Enums\PromotionError;
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
use App\Services\Promotions\PromotionEligibility;
use App\Support\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Places an order from the customer's cart inside one database transaction.
 *
 * Prices, discounts and totals are always recalculated here. Concurrent checkouts
 * lock product rows in id order, then decrement stock only when enough remains
 * (`WHERE stock_quantity >= quantity`), so two buyers cannot push inventory below zero.
 */
class CheckoutService
{
    public function __construct(
        private readonly CartCalculator $calculator,
        private readonly PromotionEligibility $eligibility,
    ) {}

    public function place(User $user, ?string $idempotencyKey = null): CheckoutResult
    {
        $key = filled($idempotencyKey) ? trim((string) $idempotencyKey) : null;

        return DB::transaction(function () use ($user, $key) {
            $cart = Cart::query()->where('user_id', $user->id)->lockForUpdate()->first();

            if ($key !== null) {
                $existing = Order::query()
                    ->where('user_id', $user->id)
                    ->where('idempotency_key', $key)
                    ->with('items')
                    ->first();

                if ($existing !== null) {
                    return new CheckoutResult($existing, replayed: true);
                }
            }

            if ($cart === null || $cart->items()->doesntExist()) {
                throw new EmptyCartException;
            }

            $summary = $this->summarizeLockedCart($cart, $user);
            $this->assertReady($summary);

            $this->decrementStock($summary);
            $this->consumePromotion($summary, $user);

            $order = $this->createOrder($summary, $user, $key);

            $cart->items()->delete();
            $cart->update(['promotion_id' => null]);

            return new CheckoutResult($order->load('items'));
        });
    }

    private function summarizeLockedCart(Cart $cart, User $user): CartSummary
    {
        $items = $cart->items()->orderBy('product_id')->get();
        $productIds = $items->pluck('product_id')->unique()->sort()->values();

        /** @var Collection<int, Product> $products */
        $products = Product::withTrashed()
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

        $this->eligibility->check($promotion, $user, $summary->subtotalCents);

        $query = Promotion::query()->whereKey($promotion->id);

        if ($promotion->usage_limit !== null) {
            $query->whereColumn('used_count', '<', 'usage_limit');
        }

        if ($query->increment('used_count') === 0) {
            throw new PromotionException(PromotionError::UsageLimitReached);
        }
    }

    private function createOrder(CartSummary $summary, User $user, ?string $idempotencyKey): Order
    {
        $order = Order::query()->create([
            'user_id' => $user->id,
            'promotion_id' => $summary->promotion?->id,
            'promotion_code' => $summary->promotion?->code,
            'status' => OrderStatus::Pending,
            'subtotal' => Money::format($summary->subtotalCents),
            'discount_amount' => Money::format($summary->discountCents),
            'total' => Money::format($summary->totalCents()),
            'idempotency_key' => $idempotencyKey,
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
