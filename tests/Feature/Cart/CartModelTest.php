<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_has_a_single_cart(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->for($user)->create();

        $this->assertTrue($user->cart->is($cart));

        $this->expectException(QueryException::class);
        Cart::factory()->for($user)->create();
    }

    public function test_multiple_guest_carts_can_exist(): void
    {
        Cart::factory()->guest()->create();
        Cart::factory()->guest()->create();

        $this->assertSame(2, Cart::query()->whereNull('user_id')->count());
    }

    public function test_cart_cannot_hold_the_same_product_twice(): void
    {
        $cart = Cart::factory()->create();
        $product = Product::factory()->create();
        CartItem::factory()->for($cart)->for($product)->create();

        $this->expectException(QueryException::class);
        CartItem::factory()->for($cart)->for($product)->create();
    }

    public function test_cart_item_still_resolves_soft_deleted_products(): void
    {
        $item = CartItem::factory()->create();
        $item->product->delete();

        $this->assertNotNull($item->fresh()->product);
        $this->assertTrue($item->fresh()->product->trashed());
    }

    public function test_deleting_a_user_deletes_the_cart_and_its_items(): void
    {
        $item = CartItem::factory()->create();
        $user = $item->cart->user;

        $user->delete();

        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_is_empty(): void
    {
        $cart = Cart::factory()->create();
        $this->assertTrue($cart->isEmpty());

        CartItem::factory()->for($cart)->create();
        $this->assertFalse($cart->isEmpty());
    }
}
