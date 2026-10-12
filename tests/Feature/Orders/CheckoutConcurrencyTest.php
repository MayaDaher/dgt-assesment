<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\Inventory\InsufficientStockException;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Checkout\CheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Stock safety for the brief's concurrency scenario (stock 5, buyers wanting 4 and 3).
 *
 * Adding to a cart now reserves units (see StockReservationTest). These cases still
 * cover checkout as the last line of defence: factory-built overlapping carts without
 * a hold cannot push `stock_quantity` below zero because of `lockForUpdate` plus the
 * guarded decrement `WHERE stock_quantity >= quantity`.
 */
class CheckoutConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Cart}
     */
    private function cartFor(User $user, Product $product, int $quantity): array
    {
        $cart = Cart::factory()->for($user)->create();
        CartItem::factory()->for($cart)->for($product)->create(['quantity' => $quantity]);

        return [$user, $cart];
    }

    public function test_the_second_buyer_is_blocked_at_add_to_cart_when_the_first_reserved_the_stock(): void
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
            ->assertJsonPath('details.available_quantity', 1);

        Sanctum::actingAs($buyerA);
        $this->postJson('/api/checkout')->assertCreated();
        $this->assertSame(1, $product->fresh()->stock_quantity);

        Sanctum::actingAs($buyerB);
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 3])
            ->assertConflict()
            ->assertJsonPath('details.available_quantity', 1);
    }

    public function test_overlapping_purchases_never_make_stock_negative(): void
    {
        $product = Product::factory()->priced('120.00')->withStock(5)->create(['name' => 'Limited Edition Sneakers']);

        $buyerA = User::factory()->create();
        $buyerB = User::factory()->create();

        $this->cartFor($buyerA, $product, 4);
        $this->cartFor($buyerB, $product, 3);

        Sanctum::actingAs($buyerA);
        $this->postJson('/api/checkout')
            ->assertCreated()
            ->assertJsonPath('data.items.0.quantity', 4);

        $this->assertSame(1, $product->fresh()->stock_quantity);

        Sanctum::actingAs($buyerB);
        $this->postJson('/api/checkout')
            ->assertConflict()
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('details.requested_quantity', 3)
            ->assertJsonPath('details.available_quantity', 1);

        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertGreaterThanOrEqual(0, $product->fresh()->stock_quantity);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, CartItem::query()->where('cart_id', $buyerB->cart->id)->count());
        $this->assertSame(0, CartItem::query()->where('cart_id', $buyerA->cart->id)->count());
    }

    public function test_two_buyers_who_fit_exactly_in_stock_both_succeed(): void
    {
        $product = Product::factory()->priced('10.00')->withStock(5)->create();

        $buyerA = User::factory()->create();
        $buyerB = User::factory()->create();

        $this->cartFor($buyerA, $product, 2);
        $this->cartFor($buyerB, $product, 3);

        Sanctum::actingAs($buyerA);
        $this->postJson('/api/checkout')->assertCreated();

        Sanctum::actingAs($buyerB);
        $this->postJson('/api/checkout')->assertCreated();

        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertSame(2, Order::query()->count());
        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_two_buyers_competing_for_the_last_units_leave_stock_at_zero(): void
    {
        $product = Product::factory()->priced('10.00')->withStock(2)->create();

        $buyerA = User::factory()->create();
        $buyerB = User::factory()->create();

        $this->cartFor($buyerA, $product, 2);
        $this->cartFor($buyerB, $product, 2);

        Sanctum::actingAs($buyerA);
        $this->postJson('/api/checkout')->assertCreated();

        Sanctum::actingAs($buyerB);
        $this->postJson('/api/checkout')
            ->assertConflict()
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('details.available_quantity', 0);

        $this->assertSame(0, $product->fresh()->stock_quantity);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_cancelling_the_winning_order_lets_the_other_buyer_purchase(): void
    {
        $product = Product::factory()->priced('120.00')->withStock(5)->create(['name' => 'Limited Edition Sneakers']);

        $buyerA = User::factory()->create();
        $buyerB = User::factory()->create();

        $this->cartFor($buyerA, $product, 4);
        $this->cartFor($buyerB, $product, 3);

        Sanctum::actingAs($buyerA);
        $orderId = $this->postJson('/api/checkout')->assertCreated()->json('data.id');

        Sanctum::actingAs($buyerB);
        $this->postJson('/api/checkout')->assertConflict();
        $this->assertSame(1, $product->fresh()->stock_quantity);

        Sanctum::actingAs($buyerA);
        $this->postJson("/api/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $this->assertSame(5, $product->fresh()->stock_quantity);

        Sanctum::actingAs($buyerB);
        $this->postJson('/api/checkout')
            ->assertCreated()
            ->assertJsonPath('data.items.0.quantity', 3);

        $this->assertSame(2, $product->fresh()->stock_quantity);
        $this->assertSame(1, Order::query()->where('status', OrderStatus::Pending)->count());
        $this->assertSame(1, Order::query()->where('status', OrderStatus::Cancelled)->count());
    }

    public function test_service_place_rejects_the_second_overlapping_checkout_without_creating_an_order(): void
    {
        $product = Product::factory()->priced('50.00')->withStock(5)->create();
        $buyerA = User::factory()->create();
        $buyerB = User::factory()->create();

        $this->cartFor($buyerA, $product, 4);
        $this->cartFor($buyerB, $product, 3);

        $checkout = $this->app->make(CheckoutService::class);

        $first = $checkout->place($buyerA);
        $this->assertSame(OrderStatus::Pending, $first->order->status);
        $this->assertSame(1, $product->fresh()->stock_quantity);

        try {
            $checkout->place($buyerB);
            $this->fail('The second overlapping checkout should have been rejected.');
        } catch (InsufficientStockException $exception) {
            $this->assertSame('INSUFFICIENT_STOCK', $exception->errorCode());
        }

        $this->assertSame(1, $product->fresh()->stock_quantity);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, CartItem::query()->count());
    }

    public function test_products_are_locked_in_id_order_so_multi_item_checkouts_both_succeed(): void
    {
        $first = Product::factory()->priced('10.00')->withStock(2)->create();
        $second = Product::factory()->priced('12.00')->withStock(2)->create();

        $buyerA = User::factory()->create();
        $buyerB = User::factory()->create();

        $cartA = Cart::factory()->for($buyerA)->create();
        CartItem::factory()->for($cartA)->for($second)->create(['quantity' => 1]);
        CartItem::factory()->for($cartA)->for($first)->create(['quantity' => 1]);

        $cartB = Cart::factory()->for($buyerB)->create();
        CartItem::factory()->for($cartB)->for($first)->create(['quantity' => 1]);
        CartItem::factory()->for($cartB)->for($second)->create(['quantity' => 1]);

        Sanctum::actingAs($buyerA);
        $this->postJson('/api/checkout')->assertCreated();

        Sanctum::actingAs($buyerB);
        $this->postJson('/api/checkout')->assertCreated();

        $this->assertSame(0, $first->fresh()->stock_quantity);
        $this->assertSame(0, $second->fresh()->stock_quantity);
        $this->assertSame(2, Order::query()->count());
    }
}
