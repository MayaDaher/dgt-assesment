<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutTest extends TestCase
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
     * @return array{0: Product, 1: CartItem}
     */
    private function addToCart(string $price, int $quantity, int $stock, ?User $user = null, ?string $name = null): array
    {
        $owner = $user ?? $this->customer;
        $cart = Cart::query()->firstOrCreate(['user_id' => $owner->id]);
        $product = Product::factory()->priced($price)->withStock($stock)->create(
            array_filter(['name' => $name])
        );
        $item = CartItem::factory()->for($cart)->for($product)->create(['quantity' => $quantity]);

        return [$product, $item];
    }

    public function test_checkout_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/checkout')->assertUnauthorized();
        $this->getJson('/api/orders')->assertUnauthorized();
        $this->getJson('/api/orders/1')->assertUnauthorized();
    }

    public function test_empty_cart_cannot_be_checked_out(): void
    {
        $this->postJson('/api/checkout')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CART_EMPTY');
    }

    public function test_successful_checkout_creates_an_order_reduces_stock_and_clears_the_cart(): void
    {
        [$first] = $this->addToCart('19.99', 2, 10, name: 'Headphones');
        [$second] = $this->addToCart('10.01', 1, 5, name: 'Cable');

        $response = $this->postJson('/api/checkout', [
            'subtotal' => '0.01',
            'total' => '0.01',
            'items' => [['product_id' => $first->id, 'price' => '0.01']],
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Order placed.')
            ->assertJsonPath('data.status', OrderStatus::Pending->value)
            ->assertJsonPath('data.subtotal', '49.99')
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.total', '49.99')
            ->assertJsonPath('data.promotion', null)
            ->assertJsonPath('data.cancellable', true)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonFragment([
                'product_name' => 'Headphones',
                'unit_price' => '19.99',
                'quantity' => 2,
                'line_total' => '39.98',
            ])
            ->assertJsonFragment([
                'product_name' => 'Cable',
                'unit_price' => '10.01',
                'quantity' => 1,
                'line_total' => '10.01',
            ]);

        $this->assertSame(0, CartItem::query()->count());
        $this->assertNull(Cart::query()->where('user_id', $this->customer->id)->value('promotion_id'));
        $this->assertSame(8, $first->fresh()->stock_quantity);
        $this->assertSame(4, $second->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_checkout_applies_a_promotion_and_records_usage(): void
    {
        $promotion = Promotion::factory()->percentage(20, 50)->minCart(100)->create(['code' => 'SUMMER20']);
        $this->addToCart('120.00', 1, 5);
        Cart::query()->where('user_id', $this->customer->id)->update(['promotion_id' => $promotion->id]);

        $this->postJson('/api/checkout')
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '120.00')
            ->assertJsonPath('data.discount', '24.00')
            ->assertJsonPath('data.total', '96.00')
            ->assertJsonPath('data.promotion.code', 'SUMMER20');

        $this->assertSame(1, $promotion->fresh()->used_count);
        $this->assertDatabaseHas('promotion_usages', [
            'promotion_id' => $promotion->id,
            'user_id' => $this->customer->id,
        ]);
        $this->assertNull(Cart::query()->where('user_id', $this->customer->id)->value('promotion_id'));
    }

    public function test_order_items_keep_snapshots_when_the_catalog_changes(): void
    {
        [$product] = $this->addToCart('50.00', 2, 5, name: 'Original Name');
        $originalSku = $product->sku;

        $orderId = $this->postJson('/api/checkout')->assertCreated()->json('data.id');

        $product->update(['name' => 'Renamed', 'sku' => 'CHANGED', 'price' => '9.99']);

        $this->getJson("/api/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.items.0.product_name', 'Original Name')
            ->assertJsonPath('data.items.0.product_sku', $originalSku)
            ->assertJsonPath('data.items.0.unit_price', '50.00')
            ->assertJsonPath('data.items.0.line_total', '100.00')
            ->assertJsonPath('data.total', '100.00');
    }

    public function test_failed_checkout_does_not_consume_stock_or_create_an_order(): void
    {
        $promotion = Promotion::factory()->percentage(20)->minCart(100)->create(['code' => 'BIGMIN']);
        [$product] = $this->addToCart('40.00', 1, 7);
        Cart::query()->where('user_id', $this->customer->id)->update(['promotion_id' => $promotion->id]);

        $this->postJson('/api/checkout')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PROMOTION_MINIMUM_NOT_MET');

        $this->assertSame(7, $product->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(0, $promotion->fresh()->used_count);
        $this->assertSame(1, CartItem::query()->count());
    }

    public function test_insufficient_stock_at_checkout_returns_409(): void
    {
        [$product] = $this->addToCart('10.00', 4, 10);
        $product->update(['stock_quantity' => 2]);

        $this->postJson('/api/checkout')
            ->assertConflict()
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('details.available_quantity', 2);

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(2, $product->fresh()->stock_quantity);
        $this->assertSame(1, CartItem::query()->count());
    }

    public function test_inactive_cart_item_blocks_checkout(): void
    {
        [$product] = $this->addToCart('10.00', 1, 5);
        $product->update(['status' => ProductStatus::Inactive]);

        $this->postJson('/api/checkout')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PRODUCT_UNAVAILABLE');

        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_concurrent_style_purchases_never_make_stock_negative(): void
    {
        $product = Product::factory()->priced('120.00')->withStock(5)->create(['name' => 'Limited Edition Sneakers']);

        $buyerA = $this->customer;
        $buyerB = User::factory()->create();

        CartItem::factory()->for(Cart::factory()->for($buyerA))->for($product)->create(['quantity' => 4]);
        CartItem::factory()->for(Cart::factory()->for($buyerB))->for($product)->create(['quantity' => 3]);

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
    }

    public function test_promotion_usage_limit_is_enforced_at_checkout(): void
    {
        $promotion = Promotion::factory()->usageLimit(1, used: 0)->fixed(5)->create(['code' => 'ONCE']);
        $other = User::factory()->create();

        $this->addToCart('20.00', 1, 5);
        Cart::query()->where('user_id', $this->customer->id)->update(['promotion_id' => $promotion->id]);
        $this->postJson('/api/checkout')->assertCreated();

        $this->addToCart('20.00', 1, 5, user: $other);
        Cart::query()->where('user_id', $other->id)->update(['promotion_id' => $promotion->id]);
        Sanctum::actingAs($other);

        $this->postJson('/api/checkout')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PROMOTION_USAGE_LIMIT_REACHED');

        $this->assertSame(1, $promotion->fresh()->used_count);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_idempotent_checkout_returns_the_same_order_without_charging_twice(): void
    {
        [$product] = $this->addToCart('25.00', 2, 10);

        $first = $this->withHeaders(['Idempotency-Key' => 'retry-order-1'])->postJson('/api/checkout');
        $first->assertCreated()->assertJsonPath('data.total', '50.00');

        $this->addToCart('99.00', 1, 10);

        $second = $this->withHeaders(['Idempotency-Key' => 'retry-order-1'])->postJson('/api/checkout');
        $second
            ->assertOk()
            ->assertJsonPath('message', 'Order already placed.')
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.total', '50.00');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(8, $product->fresh()->stock_quantity);
    }

    public function test_short_idempotency_key_is_rejected(): void
    {
        $this->withHeaders(['Idempotency-Key' => 'short'])->postJson('/api/checkout')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['idempotency_key']);
    }

    public function test_customer_can_list_and_view_only_their_orders(): void
    {
        $mine = Order::factory()->for($this->customer)->create(['total' => '10.00', 'subtotal' => '10.00']);
        OrderItem::factory()->for($mine)->create([
            'product_name' => 'Mine',
            'product_sku' => 'MINE-1',
            'unit_price' => '10.00',
            'quantity' => 1,
            'line_total' => '10.00',
        ]);
        $theirs = Order::factory()->create();

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.items.0.product_name', 'Mine');

        $this->getJson("/api/orders/{$mine->id}")->assertOk()->assertJsonPath('data.id', $mine->id);
        $this->getJson("/api/orders/{$theirs->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
        $this->getJson('/api/orders/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Order not found.');
    }

    public function test_admin_can_view_another_customers_order(): void
    {
        $order = Order::factory()->for($this->customer)->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson("/api/orders/{$order->id}")->assertOk()->assertJsonPath('data.id', $order->id);
    }
}
