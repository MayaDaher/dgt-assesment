<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        $this->customer = User::factory()->create([
            'name' => 'Ada Customer',
            'email' => 'ada@example.com',
        ]);
        Sanctum::actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guests_and_customers_cannot_list_admin_orders(): void
    {
        $order = Order::factory()->for($this->customer)->create();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/orders')->assertUnauthorized();
        $this->getJson("/api/admin/orders/{$order->id}")->assertUnauthorized();
        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => 'paid'])->assertUnauthorized();

        Sanctum::actingAs($this->customer);
        $this->getJson('/api/admin/orders')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => 'paid'])
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_admin_lists_every_customers_orders_with_the_customer_embedded(): void
    {
        $mine = Order::factory()->for($this->customer)->create(['total' => '10.00', 'subtotal' => '10.00']);
        OrderItem::factory()->for($mine)->create([
            'product_name' => 'Widget',
            'product_sku' => 'WID-1',
            'unit_price' => '10.00',
            'quantity' => 1,
            'line_total' => '10.00',
        ]);
        $other = Order::factory()->create(['total' => '20.00', 'subtotal' => '20.00']);

        $this->getJson('/api/admin/orders')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $other->id)
            ->assertJsonPath('data.1.id', $mine->id)
            ->assertJsonPath('data.1.customer.email', 'ada@example.com')
            ->assertJsonPath('data.1.items.0.product_name', 'Widget');
    }

    public function test_admin_can_filter_orders_by_status_user_search_and_date(): void
    {
        Carbon::setTestNow('2026-03-01 12:00:00');
        $matching = Order::factory()->for($this->customer)->paid()->create([
            'order_number' => 'ORD-20260301-FINDME',
            'total' => '10.00',
            'subtotal' => '10.00',
        ]);

        Carbon::setTestNow('2026-04-01 12:00:00');
        Order::factory()->for($this->customer)->create(['total' => '11.00', 'subtotal' => '11.00']);
        Order::factory()->paid()->create(['total' => '12.00', 'subtotal' => '12.00']);

        $this->getJson('/api/admin/orders?status=paid')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/admin/orders?user_id='.$this->customer->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/admin/orders?search=FINDME')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);

        $this->getJson('/api/admin/orders?search=ada@')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/admin/orders?from=2026-03-01&to=2026-03-01')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matching->id);

        $this->getJson('/api/admin/orders?status=banana')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->getJson('/api/admin/orders?from=2026-04-01&to=2026-03-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to']);
    }

    public function test_admin_can_view_an_order_with_customer_details(): void
    {
        $order = Order::factory()->for($this->customer)->create();

        $this->getJson("/api/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.customer.id', $this->customer->id)
            ->assertJsonPath('data.customer.email', 'ada@example.com')
            ->assertJsonPath('data.customer.role', 'customer');

        $this->getJson('/api/admin/orders/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Order not found.');
    }

    public function test_customer_order_list_does_not_embed_the_customer(): void
    {
        Order::factory()->for($this->customer)->create();
        Sanctum::actingAs($this->customer);

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonMissingPath('data.0.customer');
    }

    public function test_admin_advances_status_along_the_fulfilment_path(): void
    {
        $order = Order::factory()->for($this->customer)->create();

        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Paid->value])
            ->assertOk()
            ->assertJsonPath('message', 'Order status updated.')
            ->assertJsonPath('data.status', OrderStatus::Paid->value)
            ->assertJsonPath('data.cancellable', true);

        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Processing->value])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Processing->value)
            ->assertJsonPath('data.cancellable', false);

        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Shipped->value])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Shipped->value);

        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Delivered->value])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Delivered->value);

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_admin_cannot_skip_reverse_or_repeat_a_status(): void
    {
        $order = Order::factory()->for($this->customer)->create();

        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Shipped->value])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_ORDER_TRANSITION')
            ->assertJsonPath('details.current_status', OrderStatus::Pending->value)
            ->assertJsonPath('details.requested_status', OrderStatus::Shipped->value)
            ->assertJsonPath('details.allowed_statuses.0', OrderStatus::Paid->value);

        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Paid->value])->assertOk();

        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Paid->value])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_ORDER_TRANSITION')
            ->assertJsonPath('details.current_status', OrderStatus::Paid->value)
            ->assertJsonPath('details.allowed_statuses.0', OrderStatus::Processing->value);

        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Pending->value])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_ORDER_TRANSITION');
    }

    public function test_admin_cannot_mark_an_order_cancelled_through_the_status_endpoint(): void
    {
        $order = Order::factory()->for($this->customer)->create();

        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Cancelled->value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_terminal_orders_cannot_change_status(): void
    {
        $delivered = Order::factory()->for($this->customer)->delivered()->create();
        $cancelled = Order::factory()->for($this->customer)->cancelled()->create();

        $this->patchJson("/api/admin/orders/{$delivered->id}", ['status' => OrderStatus::Shipped->value])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_ORDER_TRANSITION')
            ->assertJsonPath('details.allowed_statuses', []);

        $this->patchJson("/api/admin/orders/{$cancelled->id}", ['status' => OrderStatus::Paid->value])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_ORDER_TRANSITION');
    }

    public function test_status_is_required_and_must_be_a_known_value(): void
    {
        $order = Order::factory()->for($this->customer)->create();

        $this->patchJson("/api/admin/orders/{$order->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => 'banana'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }
}
