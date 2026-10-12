<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\Product;
use App\Models\StockReservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cart.reservation_ttl_minutes' => 15]);
    }

    public function test_adding_to_the_cart_reserves_stock_without_decrementing_on_hand(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $product = Product::factory()->priced('10.00')->withStock(5)->create();

        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 4])
            ->assertCreated()
            ->assertJsonPath('data.reservation_expires_at', now()->addMinutes(15)->toIso8601String());

        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_reservations', [
            'product_id' => $product->id,
            'quantity' => 4,
        ]);
    }

    public function test_a_second_cart_cannot_reserve_more_than_the_remaining_units(): void
    {
        $product = Product::factory()->priced('120.00')->withStock(5)->create(['name' => 'Limited Edition Sneakers']);
        $buyerA = User::factory()->create();
        $buyerB = User::factory()->create();

        Sanctum::actingAs($buyerA);
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 4])->assertCreated();

        Sanctum::actingAs($buyerB);
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 3])
            ->assertConflict()
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('details.requested_quantity', 3)
            ->assertJsonPath('details.available_quantity', 1);

        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertSame(4, (int) StockReservation::query()->where('product_id', $product->id)->sum('quantity'));
    }

    public function test_reducing_quantity_releases_units_for_another_cart(): void
    {
        $product = Product::factory()->withStock(5)->create();
        $buyerA = User::factory()->create();
        $buyerB = User::factory()->create();

        Sanctum::actingAs($buyerA);
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 4])->assertCreated();
        $itemId = $buyerA->cart->items()->sole()->id;
        $this->patchJson("/api/cart/items/{$itemId}", ['quantity' => 2])->assertOk();

        Sanctum::actingAs($buyerB);
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 3])->assertCreated();
    }

    public function test_clearing_the_cart_releases_the_reservation(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $product = Product::factory()->withStock(5)->create();

        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 5])->assertCreated();
        $this->deleteJson('/api/cart')->assertOk();

        $this->assertDatabaseCount('stock_reservations', 0);

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 5])->assertCreated();
    }

    public function test_checkout_decrements_on_hand_stock_once_and_releases_the_hold(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $product = Product::factory()->priced('10.00')->withStock(5)->create();

        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 4])->assertCreated();
        $this->assertSame(5, $product->fresh()->stock_quantity);

        $this->postJson('/api/checkout')->assertCreated();

        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_expired_reservations_return_units_to_the_pool(): void
    {
        Carbon::setTestNow('2026-10-12 12:00:00');

        $product = Product::factory()->withStock(2)->create();
        $buyerA = User::factory()->create();
        $buyerB = User::factory()->create();

        Sanctum::actingAs($buyerA);
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])->assertCreated();

        Carbon::setTestNow('2026-10-12 12:15:00');

        Sanctum::actingAs($buyerB);
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])->assertCreated();

        Sanctum::actingAs($buyerA);
        $this->getJson('/api/cart')
            ->assertJsonPath('data.items.0.issue', 'insufficient_stock')
            ->assertJsonPath('data.items.0.available_quantity', 0)
            ->assertJsonPath('data.checkout_ready', false);

        Carbon::setTestNow();
    }

    public function test_reading_the_cart_slides_the_reservation_ttl(): void
    {
        Carbon::setTestNow('2026-10-12 12:00:00');

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $product = Product::factory()->withStock(3)->create();

        $this->postJson('/api/cart/items', ['product_id' => $product->id])->assertCreated();
        $this->assertEquals(
            '2026-10-12 12:15:00',
            StockReservation::query()->sole()->expires_at->format('Y-m-d H:i:s'),
        );

        Carbon::setTestNow('2026-10-12 12:10:00');
        $this->getJson('/api/cart')->assertOk();

        $this->assertEquals(
            '2026-10-12 12:25:00',
            StockReservation::query()->sole()->fresh()->expires_at->format('Y-m-d H:i:s'),
        );

        Carbon::setTestNow();
    }

    public function test_expire_command_deletes_only_expired_rows(): void
    {
        $cart = Cart::factory()->create();
        $live = StockReservation::factory()->for($cart)->create(['expires_at' => now()->addMinutes(10)]);
        StockReservation::factory()->for($cart)->expired()->create();

        $this->artisan('cart:expire-reservations')
            ->expectsOutputToContain('Released 1 expired stock reservation(s).')
            ->assertSuccessful();

        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertDatabaseHas('stock_reservations', ['id' => $live->id]);
    }

    public function test_guest_and_customer_reservations_compete_for_the_same_stock(): void
    {
        $product = Product::factory()->withStock(5)->create();
        $token = $this->getJson('/api/cart')->json('data.cart_token');

        $this->withHeaders(['X-Cart-Token' => $token])
            ->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 4])
            ->assertCreated();

        $this->flushHeaders();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertConflict()
            ->assertJsonPath('details.available_quantity', 1);
    }
}
