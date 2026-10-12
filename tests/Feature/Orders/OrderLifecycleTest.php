<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Events\OrderStatusChanged;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderStatusChange;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
        Sanctum::actingAs($this->customer);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array{0: Product, 1: int}
     */
    private function placeOrder(): array
    {
        $product = Product::factory()->priced('10.00')->withStock(5)->create();
        $cart = Cart::query()->firstOrCreate(['user_id' => $this->customer->id]);
        CartItem::factory()->for($cart)->for($product)->create(['quantity' => 1]);

        $orderId = $this->postJson('/api/checkout')->assertCreated()->json('data.id');

        return [$product->fresh(), $orderId];
    }

    public function test_checkout_opens_the_lifecycle_as_pending_and_payable(): void
    {
        [, $orderId] = $this->placeOrder();

        $this->getJson("/api/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Pending->value)
            ->assertJsonPath('data.payable', true)
            ->assertJsonPath('data.cancellable', true)
            ->assertJsonPath('data.paid_at', null)
            ->assertJsonPath('data.status_history.0.from_status', null)
            ->assertJsonPath('data.status_history.0.to_status', OrderStatus::Pending->value)
            ->assertJsonPath('data.status_history.0.source', OrderStatusSource::Checkout->value)
            ->assertJsonMissingPath('data.allowed_statuses');
    }

    public function test_customer_can_filter_their_orders_by_status_and_date(): void
    {
        Carbon::setTestNow('2026-03-01 12:00:00');
        $pending = Order::factory()->for($this->customer)->create(['total' => '10.00', 'subtotal' => '10.00']);

        Carbon::setTestNow('2026-04-01 12:00:00');
        Order::factory()->for($this->customer)->paid()->create(['total' => '11.00', 'subtotal' => '11.00']);
        Order::factory()->paid()->create(['total' => '12.00', 'subtotal' => '12.00']);

        $this->getJson('/api/orders?status=pending')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id);

        $this->getJson('/api/orders?from=2026-03-01&to=2026-03-01')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id);

        $this->getJson('/api/orders?status=banana')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->getJson('/api/orders?from=2026-04-01&to=2026-03-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    }

    public function test_owner_can_capture_payment_on_a_pending_order(): void
    {
        Event::fake([OrderStatusChanged::class]);

        [, $orderId] = $this->placeOrder();

        $this->postJson("/api/orders/{$orderId}/pay")
            ->assertOk()
            ->assertJsonPath('message', 'Payment captured.')
            ->assertJsonPath('data.status', OrderStatus::Paid->value)
            ->assertJsonPath('data.payable', false)
            ->assertJsonPath('data.cancellable', true)
            ->assertJsonPath('data.status_history.1.to_status', OrderStatus::Paid->value)
            ->assertJsonPath('data.status_history.1.source', OrderStatusSource::Payment->value);

        $order = Order::query()->findOrFail($orderId);
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);
        $this->assertNull($order->processing_at);

        Event::assertDispatched(
            OrderStatusChanged::class,
            fn (OrderStatusChanged $event) => $event->orderId === $orderId
                && $event->from === OrderStatus::Pending
                && $event->to === OrderStatus::Paid
                && $event->source === OrderStatusSource::Payment,
        );
    }

    public function test_capturing_payment_twice_is_idempotent(): void
    {
        [, $orderId] = $this->placeOrder();

        $this->postJson("/api/orders/{$orderId}/pay")->assertOk();
        $paidAt = Order::query()->findOrFail($orderId)->paid_at;

        $this->postJson("/api/orders/{$orderId}/pay")
            ->assertOk()
            ->assertJsonPath('message', 'Order already paid.')
            ->assertJsonPath('data.status', OrderStatus::Paid->value);

        $this->assertSame(
            $paidAt?->format('Y-m-d H:i:s'),
            Order::query()->findOrFail($orderId)->paid_at?->format('Y-m-d H:i:s'),
        );
        $this->assertSame(2, OrderStatusChange::query()->where('order_id', $orderId)->count());
    }

    public function test_non_pending_orders_cannot_be_paid(): void
    {
        $processing = Order::factory()->for($this->customer)->processing()->create();
        $cancelled = Order::factory()->for($this->customer)->cancelled()->create();

        $this->postJson("/api/orders/{$processing->id}/pay")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'ORDER_NOT_PAYABLE')
            ->assertJsonPath('details.current_status', OrderStatus::Processing->value)
            ->assertJsonPath('details.payable_statuses.0', OrderStatus::Pending->value);

        $this->postJson("/api/orders/{$cancelled->id}/pay")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'ORDER_NOT_PAYABLE');
    }

    public function test_another_customer_and_admin_cannot_capture_payment(): void
    {
        [, $orderId] = $this->placeOrder();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson("/api/orders/{$orderId}/pay")
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson("/api/orders/{$orderId}/pay")
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');

        $this->assertSame(OrderStatus::Pending, Order::query()->findOrFail($orderId)->status);
    }

    public function test_payment_does_not_change_stock_again(): void
    {
        [$product, $orderId] = $this->placeOrder();

        $this->assertSame(4, $product->stock_quantity);
        $this->postJson("/api/orders/{$orderId}/pay")->assertOk();
        $this->assertSame(4, $product->fresh()->stock_quantity);
    }

    public function test_admin_fulfilment_stamps_timestamps_and_history_without_clearing_earlier_ones(): void
    {
        $order = Order::factory()->for($this->customer)->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Paid->value])
            ->assertOk()
            ->assertJsonPath('data.allowed_statuses.0', OrderStatus::Processing->value);

        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Processing->value])->assertOk();
        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Shipped->value])->assertOk();
        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Delivered->value])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Delivered->value)
            ->assertJsonPath('data.payable', false)
            ->assertJsonPath('data.cancellable', false)
            ->assertJsonPath('data.allowed_statuses', []);

        $fresh = $order->fresh();
        $this->assertNotNull($fresh->paid_at);
        $this->assertNotNull($fresh->processing_at);
        $this->assertNotNull($fresh->shipped_at);
        $this->assertNotNull($fresh->delivered_at);
        $this->assertTrue($fresh->paid_at->lte($fresh->processing_at));
        $this->assertSame(4, $fresh->statusChanges()->count());
        $this->assertSame(OrderStatusSource::Admin, $fresh->statusChanges()->latest('id')->first()?->source);
    }

    public function test_cancel_appends_a_cancel_history_row(): void
    {
        [, $orderId] = $this->placeOrder();
        $this->postJson("/api/orders/{$orderId}/pay")->assertOk();

        $this->postJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value)
            ->assertJsonPath('data.payable', false)
            ->assertJsonPath('data.status_history.2.to_status', OrderStatus::Cancelled->value)
            ->assertJsonPath('data.status_history.2.source', OrderStatusSource::Cancel->value);

        $this->assertNotNull(Order::query()->findOrFail($orderId)->cancelled_at);
    }

    public function test_status_changes_cannot_be_updated(): void
    {
        $change = OrderStatusChange::query()->create([
            'order_id' => Order::factory()->create()->id,
            'from_status' => null,
            'to_status' => OrderStatus::Pending,
            'source' => OrderStatusSource::Checkout,
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Order status changes are append-only.');

        $change->update(['to_status' => OrderStatus::Paid]);
    }
}
