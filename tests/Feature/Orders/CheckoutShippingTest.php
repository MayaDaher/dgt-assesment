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

class CheckoutShippingTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_requires_a_shipping_address(): void
    {
        $customer = User::factory()->create();
        Sanctum::actingAs($customer);
        CartItem::factory()
            ->for(Cart::factory()->for($customer))
            ->for(Product::factory()->priced('10.00')->withStock(5))
            ->create(['quantity' => 1]);

        $this->postJson('/api/checkout')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shipping']);
    }

    public function test_shipping_is_snapshotted_and_not_rewritten_on_idempotent_retry(): void
    {
        $customer = User::factory()->create();
        Sanctum::actingAs($customer);
        CartItem::factory()
            ->for(Cart::factory()->for($customer))
            ->for(Product::factory()->priced('10.00')->withStock(5))
            ->create(['quantity' => 1]);

        $this->checkout([], ['Idempotency-Key' => 'ship-once-please'])
            ->assertCreated()
            ->assertJsonPath('data.shipping.name', 'Maya Daher')
            ->assertJsonPath('data.shipping.city', 'Beirut');

        $this->checkout([
            'shipping' => ['name' => 'Someone Else', 'city' => 'Tripoli'],
        ], ['Idempotency-Key' => 'ship-once-please'])
            ->assertOk()
            ->assertJsonPath('data.shipping.name', 'Maya Daher')
            ->assertJsonPath('data.shipping.city', 'Beirut');

        $this->assertSame('Maya Daher', Order::query()->value('shipping_name'));
        $this->assertSame('Beirut', Order::query()->value('shipping_city'));
    }
}
