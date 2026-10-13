<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Events\OrderStatusChanged;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpirePendingOrdersTest extends TestCase
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
    private function placeOrder(int $quantity = 2, int $stock = 5, ?Promotion $promotion = null): array
    {
        $product = Product::factory()->priced('10.00')->withStock($stock)->create();
        $cart = Cart::query()->firstOrCreate(['user_id' => $this->customer->id]);
        CartItem::factory()->for($cart)->for($product)->create(['quantity' => $quantity]);

        if ($promotion !== null) {
            $cart->update(['promotion_id' => $promotion->id]);
        }

        $orderId = $this->checkout()->assertCreated()->json('data.id');

        return [$product->fresh(), $orderId];
    }

    public function test_unpaid_pending_orders_keep_stock_until_the_payment_window_elapses(): void
    {
        [$product, $orderId] = $this->placeOrder();

        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertNotNull(Order::query()->findOrFail($orderId)->payment_due_at);

        $this->artisan('orders:expire-pending')
            ->expectsOutputToContain('Expired 0 unpaid pending order(s).')
            ->assertSuccessful();

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertTrue($order->canBePaid());
    }

    public function test_abandoned_pending_orders_are_cancelled_and_stock_is_restored(): void
    {
        Event::fake([OrderStatusChanged::class]);

        $promotion = Promotion::factory()->fixed(5)->create([
            'code' => 'HOLD5',
            'used_count' => 0,
        ]);
        [$product, $orderId] = $this->placeOrder(quantity: 2, stock: 5, promotion: $promotion);

        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertSame(1, $promotion->fresh()->used_count);

        Order::query()->whereKey($orderId)->update([
            'payment_due_at' => now()->subMinute(),
        ]);

        $this->artisan('orders:expire-pending')
            ->expectsOutputToContain('Expired 1 unpaid pending order(s).')
            ->assertSuccessful();

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame(OrderStatus::Cancelled, $order->status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertFalse($order->canBePaid());
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertSame(0, $promotion->fresh()->used_count);
        $this->assertDatabaseMissing('promotion_usages', ['order_id' => $orderId]);

        $this->assertDatabaseHas('order_status_changes', [
            'order_id' => $orderId,
            'to_status' => OrderStatus::Cancelled->value,
            'source' => OrderStatusSource::Expiry->value,
            'user_id' => null,
        ]);

        Event::assertDispatched(
            OrderStatusChanged::class,
            fn (OrderStatusChanged $event) => $event->orderId === $orderId
                && $event->to === OrderStatus::Cancelled
                && $event->source === OrderStatusSource::Expiry,
        );
    }

    public function test_expire_command_is_idempotent_and_ignores_paid_orders(): void
    {
        [$held, $pendingId] = $this->placeOrder(quantity: 1, stock: 4);

        $paidProduct = Product::factory()->priced('8.00')->withStock(4)->create();
        CartItem::factory()
            ->for(Cart::query()->firstOrCreate(['user_id' => $this->customer->id]))
            ->for($paidProduct)
            ->create(['quantity' => 1]);
        $paidId = $this->checkout()->assertCreated()->json('data.id');
        $this->postJson("/api/orders/{$paidId}/pay")->assertOk();

        Order::query()->whereKey([$pendingId, $paidId])->update([
            'payment_due_at' => now()->subHour(),
        ]);

        $this->artisan('orders:expire-pending')
            ->expectsOutputToContain('Expired 1 unpaid pending order(s).')
            ->assertSuccessful();

        $this->artisan('orders:expire-pending')
            ->expectsOutputToContain('Expired 0 unpaid pending order(s).')
            ->assertSuccessful();

        $this->assertSame(OrderStatus::Cancelled, Order::query()->findOrFail($pendingId)->status);
        $this->assertSame(OrderStatus::Paid, Order::query()->findOrFail($paidId)->status);
        $this->assertSame(4, $held->fresh()->stock_quantity);
        $this->assertSame(3, $paidProduct->fresh()->stock_quantity);
    }

    public function test_paying_before_expiry_keeps_the_stock_committed(): void
    {
        [$product, $orderId] = $this->placeOrder(quantity: 1, stock: 2);

        $this->postJson("/api/orders/{$orderId}/pay")->assertOk();

        Order::query()->whereKey($orderId)->update([
            'payment_due_at' => now()->subHour(),
        ]);

        $this->artisan('orders:expire-pending')->assertSuccessful();

        $this->assertSame(OrderStatus::Paid, Order::query()->findOrFail($orderId)->status);
        $this->assertSame(1, $product->fresh()->stock_quantity);
    }

    public function test_expired_stock_can_be_bought_by_another_customer(): void
    {
        [$product, $orderId] = $this->placeOrder(quantity: 3, stock: 3);

        Order::query()->whereKey($orderId)->update([
            'payment_due_at' => now()->subMinute(),
        ]);
        $this->artisan('orders:expire-pending')->assertSuccessful();
        $this->assertSame(3, $product->fresh()->stock_quantity);

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $cart = Cart::query()->firstOrCreate(['user_id' => $other->id]);
        CartItem::factory()->for($cart)->for($product)->create(['quantity' => 3]);

        $this->checkout()
            ->assertCreated()
            ->assertJsonPath('data.status', OrderStatus::Pending->value);

        $this->assertSame(0, $product->fresh()->stock_quantity);
    }

    public function test_checkout_stamps_payment_due_at_from_config(): void
    {
        config(['orders.unpaid_pending_minutes' => 15]);

        [$product, $orderId] = $this->placeOrder(quantity: 1, stock: 2);

        $due = Order::query()->findOrFail($orderId)->payment_due_at;
        $this->assertNotNull($due);
        $this->assertEqualsWithDelta(now()->addMinutes(15)->getTimestamp(), $due->getTimestamp(), 2);
        $this->assertSame(1, $product->fresh()->stock_quantity);
    }
}
