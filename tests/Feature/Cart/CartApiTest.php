<?php

namespace Tests\Feature\Cart;

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CartApiTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
    }

    private function actingAsCustomer(?User $user = null): void
    {
        Sanctum::actingAs($user ?? $this->customer);
    }

    private function cartItemFor(User $user, Product $product, int $quantity): CartItem
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $user->id]);

        return CartItem::factory()->for($cart)->for($product)->create(['quantity' => $quantity]);
    }

    public function test_guests_can_open_a_cart_without_authentication(): void
    {
        $token = $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.is_guest', true)
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.checkout_ready', false)
            ->assertJsonPath('data.checkout_blocker', 'CART_EMPTY')
            ->json('data.cart_token');

        $this->assertNotEmpty($token);
    }

    public function test_bearer_token_loads_the_authenticated_users_cart(): void
    {
        $token = $this->customer->createToken('api')->plainTextToken;
        $product = Product::factory()->priced('10.00')->withStock(3)->create();

        $this->withToken($token)
            ->postJson('/api/cart/items', ['product_id' => $product->id])
            ->assertCreated()
            ->assertJsonPath('data.is_guest', false)
            ->assertJsonPath('data.cart_token', null);

        $this->assertDatabaseHas('carts', ['user_id' => $this->customer->id, 'token' => null]);
    }

    public function test_invalid_bearer_token_is_rejected_on_cart_routes(): void
    {
        $this->withToken('not-a-real-token')
            ->getJson('/api/cart')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_new_customer_gets_an_empty_cart(): void
    {
        $this->actingAsCustomer();

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.items', [])
            ->assertJsonPath('data.items_count', 0)
            ->assertJsonPath('data.subtotal', '0.00')
            ->assertJsonPath('data.is_guest', false)
            ->assertJsonPath('data.cart_token', null)
            ->assertJsonPath('data.checkout_ready', false)
            ->assertJsonPath('data.checkout_blocker', 'CART_EMPTY');
    }

    public function test_customer_can_add_a_product(): void
    {
        $this->actingAsCustomer();
        $product = Product::factory()->priced('19.99')->withStock(10)->create();

        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 3])
            ->assertCreated()
            ->assertJsonPath('message', 'Item added to cart.')
            ->assertJsonPath('data.items.0.product.id', $product->id)
            ->assertJsonPath('data.items.0.quantity', 3)
            ->assertJsonPath('data.items.0.unit_price', '19.99')
            ->assertJsonPath('data.items.0.line_total', '59.97')
            ->assertJsonPath('data.subtotal', '59.97')
            ->assertJsonPath('data.total_quantity', 3)
            ->assertJsonPath('data.checkout_ready', true)
            ->assertJsonPath('data.checkout_blocker', null);

        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 3]);
    }

    public function test_quantity_defaults_to_one(): void
    {
        $this->actingAsCustomer();
        $product = Product::factory()->create();

        $this->postJson('/api/cart/items', ['product_id' => $product->id])
            ->assertCreated()
            ->assertJsonPath('data.items.0.quantity', 1);
    }

    public function test_adding_the_same_product_again_increases_quantity(): void
    {
        $this->actingAsCustomer();
        $product = Product::factory()->withStock(10)->create();

        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])->assertCreated();
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 3])
            ->assertOk()
            ->assertJsonPath('message', 'Item quantity increased.')
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 5);
    }

    public function test_cannot_add_more_than_available_stock(): void
    {
        $this->actingAsCustomer();
        $product = Product::factory()->withStock(5)->create();

        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 6])
            ->assertConflict()
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK')
            ->assertJsonPath('details', [
                'product_id' => $product->id,
                'requested_quantity' => 6,
                'available_quantity' => 5,
            ]);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_stock_check_includes_quantity_already_in_cart(): void
    {
        $this->actingAsCustomer();
        $product = Product::factory()->withStock(5)->create();
        $this->cartItemFor($this->customer, $product, 4);

        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertConflict()
            ->assertJsonPath('details.requested_quantity', 6);

        $this->assertDatabaseHas('cart_items', ['product_id' => $product->id, 'quantity' => 4]);
    }

    public function test_cannot_add_out_of_stock_product(): void
    {
        $this->actingAsCustomer();
        $product = Product::factory()->outOfStock()->create(['name' => 'Rare Item']);

        $this->postJson('/api/cart/items', ['product_id' => $product->id])
            ->assertConflict()
            ->assertJsonPath('message', '"Rare Item" is out of stock.');
    }

    public function test_cannot_add_inactive_product(): void
    {
        $this->actingAsCustomer();
        $product = Product::factory()->inactive()->create();

        $this->postJson('/api/cart/items', ['product_id' => $product->id])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PRODUCT_UNAVAILABLE');
    }

    public function test_cannot_add_missing_or_deleted_product(): void
    {
        $this->actingAsCustomer();
        $deleted = Product::factory()->create();
        $deleted->delete();

        foreach ([999999, $deleted->id] as $productId) {
            $this->postJson('/api/cart/items', ['product_id' => $productId])
                ->assertNotFound()
                ->assertJsonPath('message', 'Product not found.');
        }
    }

    public function test_add_item_validation(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/cart/items', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id']);

        $this->postJson('/api/cart/items', ['product_id' => 'abc', 'quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product_id', 'quantity']);

        $this->postJson('/api/cart/items', ['product_id' => 1, 'quantity' => CartItem::MAX_QUANTITY + 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_prices_sent_by_the_client_are_ignored(): void
    {
        $this->actingAsCustomer();
        $product = Product::factory()->priced('100.00')->create();

        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 1, 'price' => '0.01', 'unit_price' => '0.01'])
            ->assertCreated()
            ->assertJsonPath('data.items.0.unit_price', '100.00')
            ->assertJsonPath('data.subtotal', '100.00');
    }

    public function test_customer_can_update_quantity(): void
    {
        $this->actingAsCustomer();
        $item = $this->cartItemFor($this->customer, Product::factory()->priced('2.50')->withStock(10)->create(), 1);

        $this->patchJson("/api/cart/items/{$item->id}", ['quantity' => 4])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 4)
            ->assertJsonPath('data.subtotal', '10.00');
    }

    public function test_cannot_update_above_available_stock(): void
    {
        $this->actingAsCustomer();
        $item = $this->cartItemFor($this->customer, Product::factory()->withStock(3)->create(), 1);

        $this->patchJson("/api/cart/items/{$item->id}", ['quantity' => 4])
            ->assertConflict()
            ->assertJsonPath('code', 'INSUFFICIENT_STOCK');

        $this->assertSame(1, $item->fresh()->quantity);
    }

    public function test_cannot_update_quantity_of_a_discontinued_product(): void
    {
        $this->actingAsCustomer();
        $product = Product::factory()->withStock(10)->create();
        $item = $this->cartItemFor($this->customer, $product, 1);
        $product->update(['status' => ProductStatus::Inactive]);

        $this->patchJson("/api/cart/items/{$item->id}", ['quantity' => 2])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PRODUCT_UNAVAILABLE');
    }

    public function test_update_validation(): void
    {
        $this->actingAsCustomer();
        $item = $this->cartItemFor($this->customer, Product::factory()->create(), 1);

        $this->patchJson("/api/cart/items/{$item->id}", ['quantity' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);

        $this->patchJson("/api/cart/items/{$item->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity']);
    }

    public function test_customer_can_remove_an_item(): void
    {
        $this->actingAsCustomer();
        $keep = $this->cartItemFor($this->customer, Product::factory()->create(), 1);
        $remove = $this->cartItemFor($this->customer, Product::factory()->create(), 1);

        $this->deleteJson("/api/cart/items/{$remove->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $keep->id);

        $this->assertModelMissing($remove);
    }

    public function test_customer_can_clear_the_cart(): void
    {
        $this->actingAsCustomer();
        $this->cartItemFor($this->customer, Product::factory()->create(), 1);
        $this->cartItemFor($this->customer, Product::factory()->create(), 2);

        $this->deleteJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.items_count', 0);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_customer_cannot_touch_another_customers_cart_items(): void
    {
        $other = User::factory()->create();
        $foreignItem = $this->cartItemFor($other, Product::factory()->withStock(10)->create(), 1);
        $this->cartItemFor($this->customer, Product::factory()->create(), 1);
        $this->actingAsCustomer();

        $this->patchJson("/api/cart/items/{$foreignItem->id}", ['quantity' => 5])
            ->assertNotFound()
            ->assertJsonPath('message', 'Cart item not found.');
        $this->deleteJson("/api/cart/items/{$foreignItem->id}")->assertNotFound();

        $this->assertSame(1, $foreignItem->fresh()->quantity);
        $this->getJson('/api/cart')->assertJsonCount(1, 'data.items');
    }

    public function test_updating_an_item_without_a_cart_returns_404(): void
    {
        $this->actingAsCustomer();

        $this->patchJson('/api/cart/items/123', ['quantity' => 1])
            ->assertNotFound()
            ->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_cart_always_uses_current_product_prices(): void
    {
        $this->actingAsCustomer();
        $product = Product::factory()->priced('10.00')->withStock(10)->create();
        $this->cartItemFor($this->customer, $product, 2);

        $product->update(['price' => '12.50']);

        $this->getJson('/api/cart')
            ->assertJsonPath('data.items.0.unit_price', '12.50')
            ->assertJsonPath('data.subtotal', '25.00');
    }

    public function test_subtotal_is_exact_without_float_rounding(): void
    {
        $this->actingAsCustomer();
        $this->cartItemFor($this->customer, Product::factory()->priced('0.10')->withStock(10)->create(), 3);
        $this->cartItemFor($this->customer, Product::factory()->priced('0.20')->withStock(10)->create(), 1);

        $this->getJson('/api/cart')->assertJsonPath('data.subtotal', '0.50');
    }

    public function test_cart_flags_items_that_can_no_longer_be_bought(): void
    {
        $this->actingAsCustomer();
        $ok = Product::factory()->priced('10.00')->withStock(10)->create();
        $lowStock = Product::factory()->priced('20.00')->withStock(10)->create();
        $discontinued = Product::factory()->priced('30.00')->withStock(10)->create();
        $deleted = Product::factory()->priced('40.00')->withStock(10)->create();

        $this->cartItemFor($this->customer, $ok, 1);
        $this->cartItemFor($this->customer, $lowStock, 5);
        $this->cartItemFor($this->customer, $discontinued, 1);
        $this->cartItemFor($this->customer, $deleted, 1);

        $lowStock->update(['stock_quantity' => 2]);
        $discontinued->update(['status' => ProductStatus::Inactive]);
        $deleted->delete();

        $this->getJson('/api/cart')
            ->assertOk()
            ->assertJsonCount(4, 'data.items')
            ->assertJsonPath('data.items.0.issue', null)
            ->assertJsonPath('data.items.1.issue', 'insufficient_stock')
            ->assertJsonPath('data.items.1.available_quantity', 2)
            ->assertJsonPath('data.items.2.issue', 'product_unavailable')
            ->assertJsonPath('data.items.3.issue', 'product_unavailable')
            ->assertJsonPath('data.items.3.available_quantity', 0)
            ->assertJsonPath('data.subtotal', '10.00')
            ->assertJsonPath('data.has_issues', true)
            ->assertJsonPath('data.checkout_ready', false)
            ->assertJsonPath('data.checkout_blocker', 'CART_HAS_ISSUES');
    }

    public function test_each_customer_only_sees_their_own_cart(): void
    {
        $other = User::factory()->create();
        $this->cartItemFor($other, Product::factory()->create(), 1);
        $this->actingAsCustomer();

        $this->getJson('/api/cart')->assertJsonPath('data.items', []);
    }
}
