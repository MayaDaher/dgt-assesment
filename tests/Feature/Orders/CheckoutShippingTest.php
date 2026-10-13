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

    public function test_checkout_shipping_prefills_from_the_latest_order(): void
    {
        $customer = User::factory()->create();
        $stranger = User::factory()->create();

        $this->getJson('/api/checkout/shipping')
            ->assertUnauthorized();

        Sanctum::actingAs($customer);
        $this->getJson('/api/checkout/shipping')
            ->assertOk()
            ->assertJsonPath('data.shipping', null);

        Order::factory()->for($stranger)->create([
            'shipping_name' => 'Someone Else',
            'shipping_city' => 'Tripoli',
        ]);
        Order::factory()->for($customer)->create([
            'shipping_name' => 'Maya Daher',
            'shipping_city' => 'Beirut',
            'shipping_country' => 'Lebanon',
        ]);
        Order::factory()->for($customer)->create([
            'shipping_name' => 'Maya Daher',
            'shipping_phone' => '+961 70 999 000',
            'shipping_address_line1' => 'Bliss St., Building 3',
            'shipping_address_line2' => 'Floor 1',
            'shipping_city' => 'Beirut',
            'shipping_state' => 'Beirut Governorate',
            'shipping_postal_code' => '1104',
            'shipping_country' => 'Lebanon',
        ]);

        $this->getJson('/api/checkout/shipping')
            ->assertOk()
            ->assertJsonPath('data.shipping.name', 'Maya Daher')
            ->assertJsonPath('data.shipping.phone', '+961 70 999 000')
            ->assertJsonPath('data.shipping.address_line1', 'Bliss St., Building 3')
            ->assertJsonPath('data.shipping.address_line2', 'Floor 1')
            ->assertJsonPath('data.shipping.city', 'Beirut')
            ->assertJsonPath('data.shipping.postal_code', '1104')
            ->assertJsonPath('data.shipping.country', 'Lebanon');

        Sanctum::actingAs($stranger);
        $this->getJson('/api/checkout/shipping')
            ->assertOk()
            ->assertJsonPath('data.shipping.name', 'Someone Else')
            ->assertJsonPath('data.shipping.city', 'Tripoli');
    }
}
