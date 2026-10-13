<?php

namespace Tests\Feature\Orders;

use App\Events\OrderPlaced;
use App\Listeners\LogOrderPlaced;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderPlacedEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_placing_an_order_dispatches_order_placed_after_commit(): void
    {
        Event::fake([OrderPlaced::class]);

        $customer = User::factory()->create();
        CartItem::factory()
            ->for(Cart::factory()->for($customer))
            ->for(Product::factory()->priced('20.00')->withStock(5))
            ->create(['quantity' => 1]);

        Sanctum::actingAs($customer);

        $orderId = $this->checkout()->assertCreated()->json('data.id');

        Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $event) => $event->orderId === $orderId);
    }

    public function test_idempotent_replay_does_not_dispatch_order_placed_again(): void
    {
        Event::fake([OrderPlaced::class]);

        $customer = User::factory()->create();
        CartItem::factory()
            ->for(Cart::factory()->for($customer))
            ->for(Product::factory()->priced('20.00')->withStock(5))
            ->create(['quantity' => 1]);

        Sanctum::actingAs($customer);

        $this->checkout([], ['Idempotency-Key' => 'order-placed-once'])->assertCreated();
        $this->checkout([], ['Idempotency-Key' => 'order-placed-once'])->assertOk();

        Event::assertDispatchedTimes(OrderPlaced::class, 1);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_failed_checkout_does_not_dispatch_order_placed(): void
    {
        Event::fake([OrderPlaced::class]);

        Sanctum::actingAs(User::factory()->create());

        $this->checkout()->assertUnprocessable();

        Event::assertNotDispatched(OrderPlaced::class);
    }

    public function test_the_listener_is_queued(): void
    {
        $this->assertTrue(Event::hasListeners(OrderPlaced::class));

        Queue::fake();

        $order = Order::factory()->create();
        OrderPlaced::dispatch($order->id);

        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) {
            return $job->class === LogOrderPlaced::class;
        });
    }
}
