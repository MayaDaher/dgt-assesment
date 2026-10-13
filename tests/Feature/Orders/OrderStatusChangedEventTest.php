<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\OrderStatusSource;
use App\Events\OrderStatusChanged;
use App\Listeners\LogOrderStatusChanged;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderStatusChangedEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_status_changed_listener_is_queued(): void
    {
        $this->assertTrue(Event::hasListeners(OrderStatusChanged::class));
        $this->assertCount(1, Event::getListeners(OrderStatusChanged::class));

        Queue::fake();

        $order = Order::factory()->create();
        OrderStatusChanged::dispatch(
            $order->id,
            OrderStatus::Pending,
            OrderStatus::Paid,
            OrderStatusSource::Payment,
        );

        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) use ($order) {
            $event = $job->data[0] ?? null;

            return $job->class === LogOrderStatusChanged::class
                && $event instanceof OrderStatusChanged
                && $event->orderId === $order->id
                && $event->from === OrderStatus::Pending
                && $event->to === OrderStatus::Paid
                && $event->source === OrderStatusSource::Payment;
        });
    }

    public function test_listener_writes_a_structured_info_log_and_sends_no_notifications(): void
    {
        Notification::fake();

        $order = Order::factory()->paid()->create();
        $captured = [];

        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$captured): void {
            $captured[] = $log;
        });

        (new LogOrderStatusChanged)->handle(new OrderStatusChanged(
            $order->id,
            OrderStatus::Pending,
            OrderStatus::Paid,
            OrderStatusSource::Payment,
        ));

        $match = collect($captured)->first(
            fn (MessageLogged $log) => $log->message === 'Order status changed.',
        );

        $this->assertNotNull($match);
        $this->assertSame('info', $match->level);
        $this->assertSame([
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'from_status' => OrderStatus::Pending->value,
            'to_status' => OrderStatus::Paid->value,
            'source' => OrderStatusSource::Payment->value,
        ], $match->context);

        Notification::assertNothingSent();
    }

    public function test_pay_queues_a_status_changed_log(): void
    {
        Queue::fake();
        Notification::fake();

        [, $orderId] = $this->placeOrder();

        $this->postJson("/api/orders/{$orderId}/pay")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Paid->value);

        $this->assertQueuedStatusChange(
            $orderId,
            OrderStatus::Pending,
            OrderStatus::Paid,
            OrderStatusSource::Payment,
        );
        $this->assertCount(1, $this->queuedStatusChanges());

        Notification::assertNothingSent();
    }

    public function test_idempotent_pay_does_not_queue_the_listener_again(): void
    {
        [, $orderId] = $this->placeOrder();

        $this->postJson("/api/orders/{$orderId}/pay")->assertOk();

        Queue::fake();

        $this->postJson("/api/orders/{$orderId}/pay")
            ->assertOk()
            ->assertJsonPath('message', 'Order already paid.');

        $this->assertNoQueuedStatusChanges();
    }

    public function test_admin_ship_and_deliver_each_queue_a_status_log(): void
    {
        Queue::fake();
        Notification::fake();

        $order = Order::factory()->processing()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Shipped->value])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Shipped->value);

        $this->assertQueuedStatusChange(
            $order->id,
            OrderStatus::Processing,
            OrderStatus::Shipped,
            OrderStatusSource::Admin,
        );

        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Delivered->value])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Delivered->value);

        $this->assertQueuedStatusChange(
            $order->id,
            OrderStatus::Shipped,
            OrderStatus::Delivered,
            OrderStatusSource::Admin,
        );

        $this->assertCount(2, $this->queuedStatusChanges());
        Notification::assertNothingSent();
    }

    public function test_rejected_admin_skip_does_not_queue_the_listener(): void
    {
        Queue::fake();

        $order = Order::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->patchJson("/api/admin/orders/{$order->id}", ['status' => OrderStatus::Shipped->value])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_ORDER_TRANSITION');

        $this->assertNoQueuedStatusChanges();
    }

    public function test_cancel_queues_a_status_log(): void
    {
        Queue::fake();

        [, $orderId] = $this->placeOrder();

        $this->postJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $this->assertQueuedStatusChange(
            $orderId,
            OrderStatus::Pending,
            OrderStatus::Cancelled,
            OrderStatusSource::Cancel,
        );
    }

    public function test_idempotent_cancel_does_not_queue_the_listener_again(): void
    {
        [, $orderId] = $this->placeOrder();

        $this->postJson("/api/orders/{$orderId}/cancel")->assertOk();

        Queue::fake();

        $this->postJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('message', 'Order already cancelled.');

        $this->assertNoQueuedStatusChanges();
    }

    public function test_expiry_queues_a_status_log(): void
    {
        Queue::fake();

        [, $orderId] = $this->placeOrder();

        Order::query()->whereKey($orderId)->update([
            'payment_due_at' => now()->subMinute(),
        ]);

        $this->artisan('orders:expire-pending')
            ->expectsOutputToContain('Expired 1 unpaid pending order(s).')
            ->assertSuccessful();

        $this->assertQueuedStatusChange(
            $orderId,
            OrderStatus::Pending,
            OrderStatus::Cancelled,
            OrderStatusSource::Expiry,
        );
    }

    /**
     * @return array{0: Product, 1: int}
     */
    private function placeOrder(): array
    {
        $customer = User::factory()->create();
        $product = Product::factory()->priced('10.00')->withStock(5)->create();
        CartItem::factory()
            ->for(Cart::factory()->for($customer))
            ->for($product)
            ->create(['quantity' => 1]);

        Sanctum::actingAs($customer);

        $orderId = $this->checkout()->assertCreated()->json('data.id');

        return [$product->fresh(), $orderId];
    }

    /**
     * @return list<OrderStatusChanged>
     */
    private function queuedStatusChanges(): array
    {
        return Queue::listenersPushed(LogOrderStatusChanged::class)
            ->map(fn (CallQueuedListener $job) => $job->data[0])
            ->values()
            ->all();
    }

    private function assertQueuedStatusChange(
        int $orderId,
        OrderStatus $from,
        OrderStatus $to,
        OrderStatusSource $source,
    ): void {
        $match = collect($this->queuedStatusChanges())->first(
            fn (OrderStatusChanged $event) => $event instanceof OrderStatusChanged
                && $event->orderId === $orderId
                && $event->from === $from
                && $event->to === $to
                && $event->source === $source,
        );

        $this->assertNotNull(
            $match,
            sprintf(
                'Expected a queued LogOrderStatusChanged for order %d (%s → %s via %s).',
                $orderId,
                $from->value,
                $to->value,
                $source->value,
            ),
        );
    }

    private function assertNoQueuedStatusChanges(): void
    {
        $this->assertSame([], $this->queuedStatusChanges());
    }
}
