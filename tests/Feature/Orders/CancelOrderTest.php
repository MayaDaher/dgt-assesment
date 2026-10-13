<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\User;
use App\Services\Orders\CancelOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CancelOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
        Sanctum::actingAs($this->customer);
    }

    /**
     * @return array{0: Product, 1: int}
     */
    private function placeOrder(string $price, int $quantity, int $stock, ?User $user = null, ?Promotion $promotion = null): array
    {
        $owner = $user ?? $this->customer;
        $cart = Cart::query()->firstOrCreate(['user_id' => $owner->id]);
        $product = Product::factory()->priced($price)->withStock($stock)->create();
        CartItem::factory()->for($cart)->for($product)->create(['quantity' => $quantity]);

        if ($promotion !== null) {
            $cart->update(['promotion_id' => $promotion->id]);
        }

        Sanctum::actingAs($owner);
        $orderId = $this->checkout()->assertCreated()->json('data.id');

        return [$product->fresh(), $orderId];
    }

    public function test_customer_can_cancel_a_pending_order_and_stock_is_restored(): void
    {
        [$product, $orderId] = $this->placeOrder('19.99', 2, 10);

        $this->assertSame(8, $product->fresh()->stock_quantity);

        $response = $this->postJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Order cancelled.')
            ->assertJsonPath('data.id', $orderId)
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value)
            ->assertJsonPath('data.cancellable', false)
            ->assertJsonPath('data.items.0.quantity', 2);

        $this->assertNotNull($response->json('data.cancelled_at'));

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    public function test_paid_orders_can_be_cancelled(): void
    {
        $product = Product::factory()->priced('25.00')->withStock(3)->create();
        $order = Order::factory()->for($this->customer)->paid()->create([
            'subtotal' => '50.00',
            'total' => '50.00',
        ]);
        OrderItem::factory()->for($order)->for($product)->create([
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'unit_price' => '25.00',
            'quantity' => 2,
            'line_total' => '50.00',
        ]);

        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_cancelling_twice_is_idempotent_and_does_not_restore_stock_again(): void
    {
        [$product, $orderId] = $this->placeOrder('10.00', 3, 7);
        $cancelledAt = now()->subMinute();

        $first = $this->postJson("/api/orders/{$orderId}/cancel")->assertOk();
        $first
            ->assertJsonPath('message', 'Order cancelled.')
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $this->assertSame(7, $product->fresh()->stock_quantity);
        Order::query()->whereKey($orderId)->update(['cancelled_at' => $cancelledAt]);

        $this->postJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('message', 'Order already cancelled.')
            ->assertJsonPath('data.id', $orderId)
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value)
            ->assertJsonPath('data.cancellable', false);

        $this->assertSame(7, $product->fresh()->stock_quantity);
        $this->assertSame(
            $cancelledAt->format('Y-m-d H:i:s'),
            Order::query()->findOrFail($orderId)->cancelled_at->format('Y-m-d H:i:s')
        );
        $this->assertSame(1, Order::query()->count());
    }

    public function test_service_level_retry_does_not_credit_stock_twice(): void
    {
        [$product, $orderId] = $this->placeOrder('12.00', 4, 9);
        $order = Order::query()->findOrFail($orderId);
        $canceller = $this->app->make(CancelOrderService::class);

        $first = $canceller->cancel($order);
        $second = $canceller->cancel($order);

        $this->assertFalse($first->replayed);
        $this->assertTrue($second->replayed);
        $this->assertSame(9, $product->fresh()->stock_quantity);
        $this->assertSame(OrderStatus::Cancelled, $second->order->status);
    }

    public function test_non_cancellable_statuses_return_422_invalid_order_status(): void
    {
        foreach ([OrderStatus::Processing, OrderStatus::Shipped, OrderStatus::Delivered] as $status) {
            $product = Product::factory()->withStock(4)->create();
            $order = Order::factory()->for($this->customer)->create(['status' => $status]);
            OrderItem::factory()->for($order)->for($product)->create([
                'product_name' => $product->name,
                'product_sku' => $product->sku,
                'unit_price' => '10.00',
                'quantity' => 1,
                'line_total' => '10.00',
            ]);

            $this->postJson("/api/orders/{$order->id}/cancel")
                ->assertUnprocessable()
                ->assertJsonPath('code', 'INVALID_ORDER_STATUS')
                ->assertJsonPath('details.current_status', $status->value)
                ->assertJsonPath('details.cancellable_statuses.0', OrderStatus::Pending->value)
                ->assertJsonPath('details.cancellable_statuses.1', OrderStatus::Paid->value);

            $this->assertSame($status, $order->fresh()->status);
            $this->assertNull($order->fresh()->cancelled_at);
            $this->assertSame(4, $product->fresh()->stock_quantity);
        }
    }

    public function test_another_customer_cannot_cancel_the_order(): void
    {
        [, $orderId] = $this->placeOrder('15.00', 1, 5);

        Sanctum::actingAs(User::factory()->create());

        $this->postJson("/api/orders/{$orderId}/cancel")
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertSame(OrderStatus::Pending, Order::query()->findOrFail($orderId)->status);
    }

    public function test_admin_cannot_cancel_another_customers_order(): void
    {
        [, $orderId] = $this->placeOrder('15.00', 1, 5);

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/orders/{$orderId}/cancel")
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertSame(OrderStatus::Pending, Order::query()->findOrFail($orderId)->status);
    }

    public function test_missing_order_returns_404(): void
    {
        $this->postJson('/api/orders/999999/cancel')
            ->assertNotFound()
            ->assertJsonPath('message', 'Order not found.');
    }

    public function test_cancellation_releases_the_promotion_so_it_can_be_used_again(): void
    {
        $promotion = Promotion::factory()->percentage(10)->perCustomerLimit(1)->create(['code' => 'ONCEMORE']);
        [$product, $orderId] = $this->placeOrder('100.00', 1, 5, promotion: $promotion);

        $this->assertSame(1, $promotion->fresh()->used_count);
        $this->assertDatabaseHas('promotion_usages', [
            'promotion_id' => $promotion->id,
            'user_id' => $this->customer->id,
            'order_id' => $orderId,
        ]);

        $this->postJson("/api/orders/{$orderId}/cancel")->assertOk();

        $this->assertSame(0, $promotion->fresh()->used_count);
        $this->assertSame(0, PromotionUsage::query()->where('order_id', $orderId)->count());

        $cart = Cart::query()->firstOrCreate(['user_id' => $this->customer->id]);
        CartItem::factory()->for($cart)->for($product)->create(['quantity' => 1]);
        $cart->update(['promotion_id' => $promotion->id]);

        $this->checkout()
            ->assertCreated()
            ->assertJsonPath('data.promotion.code', 'ONCEMORE')
            ->assertJsonPath('data.discount', '10.00');

        $this->assertSame(1, $promotion->fresh()->used_count);
        $this->assertSame(4, $product->fresh()->stock_quantity);
    }

    public function test_retrying_cancel_does_not_release_promotion_usage_twice(): void
    {
        $promotion = Promotion::factory()->fixed(5)->usageLimit(3, used: 0)->create(['code' => 'FIVEOFF']);
        [, $orderId] = $this->placeOrder('40.00', 1, 4, promotion: $promotion);

        $this->postJson("/api/orders/{$orderId}/cancel")->assertOk();
        $this->postJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('message', 'Order already cancelled.');

        $this->assertSame(0, $promotion->fresh()->used_count);
        $this->assertSame(0, PromotionUsage::query()->count());
    }

    public function test_stock_is_restored_for_a_soft_deleted_product(): void
    {
        [$product, $orderId] = $this->placeOrder('30.00', 2, 6);

        $product->delete();
        $this->assertSoftDeleted($product);

        $this->postJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $this->assertSame(6, Product::withTrashed()->find($product->id)->stock_quantity);
        $this->assertSoftDeleted($product);
    }

    public function test_force_deleted_catalog_product_does_not_block_cancellation(): void
    {
        [$product, $orderId] = $this->placeOrder('18.00', 1, 4);

        $product->forceDelete();

        $this->postJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $item = OrderItem::query()->where('order_id', $orderId)->firstOrFail();
        $this->assertNull($item->product_id);
        $this->assertSame(OrderStatus::Cancelled, Order::query()->findOrFail($orderId)->status);
    }

    public function test_cancelled_order_is_still_visible_to_the_owner(): void
    {
        [, $orderId] = $this->placeOrder('22.00', 1, 3);

        $this->postJson("/api/orders/{$orderId}/cancel")->assertOk();

        $this->getJson("/api/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value)
            ->assertJsonPath('data.cancellable', false);

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonPath('data.0.id', $orderId)
            ->assertJsonPath('data.0.status', OrderStatus::Cancelled->value);
    }
}
