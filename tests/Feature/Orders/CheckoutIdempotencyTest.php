<?php

namespace Tests\Feature\Orders;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_same_customer_cannot_place_two_orders_with_one_key(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->priced('20.00')->withStock(10)->create();
        CartItem::factory()->for(Cart::factory()->for($customer))->for($product)->create(['quantity' => 1]);

        Sanctum::actingAs($customer);

        $first = $this->withHeaders(['Idempotency-Key' => 'shared-key-1'])->postJson('/api/checkout');
        $first->assertCreated();

        CartItem::factory()->for($customer->cart)->for(Product::factory()->priced('9.00')->withStock(5))->create(['quantity' => 1]);

        $this->withHeaders(['Idempotency-Key' => 'shared-key-1'])->postJson('/api/checkout')
            ->assertOk()
            ->assertJsonPath('message', 'Order already placed.')
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(9, $product->fresh()->stock_quantity);
    }

    public function test_different_customers_may_reuse_the_same_idempotency_key(): void
    {
        $product = Product::factory()->priced('15.00')->withStock(10)->create();
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        CartItem::factory()->for(Cart::factory()->for($alice))->for($product)->create(['quantity' => 1]);
        CartItem::factory()->for(Cart::factory()->for($bob))->for($product)->create(['quantity' => 1]);

        Sanctum::actingAs($alice);
        $aliceOrder = $this->withHeaders(['Idempotency-Key' => 'same-key-across-users'])->postJson('/api/checkout');
        $aliceOrder->assertCreated();

        Sanctum::actingAs($bob);
        $bobOrder = $this->withHeaders(['Idempotency-Key' => 'same-key-across-users'])->postJson('/api/checkout');
        $bobOrder->assertCreated();

        $this->assertNotSame($aliceOrder->json('data.id'), $bobOrder->json('data.id'));
        $this->assertSame(2, Order::query()->count());
        $this->assertSame(8, $product->fresh()->stock_quantity);
    }

    public function test_checkout_without_an_idempotency_key_still_places_an_order(): void
    {
        $customer = User::factory()->create();
        CartItem::factory()
            ->for(Cart::factory()->for($customer))
            ->for(Product::factory()->priced('11.00')->withStock(3))
            ->create(['quantity' => 1]);

        Sanctum::actingAs($customer);

        $this->postJson('/api/checkout')
            ->assertCreated()
            ->assertJsonPath('data.total', '11.00');

        $this->assertNull(Order::query()->value('idempotency_key'));
    }
}
