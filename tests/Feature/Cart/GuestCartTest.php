<?php

namespace Tests\Feature\Cart;

use App\Models\Cart;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GuestCartTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: TestResponse}
     */
    private function guestCart(): array
    {
        $this->flushHeaders();

        $response = $this->getJson('/api/cart')->assertOk();
        $token = $response->json('data.cart_token');

        $this->assertTrue(Str::isUuid($token));
        $response->assertHeader('X-Cart-Token', $token);

        return [$token, $response];
    }

    public function test_guest_can_add_update_and_remove_items_with_a_cart_token(): void
    {
        [$token] = $this->guestCart();
        $product = Product::factory()->priced('19.99')->withStock(10)->create();

        $this->withHeaders(['X-Cart-Token' => $token])
            ->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertCreated()
            ->assertHeader('X-Cart-Token', $token)
            ->assertJsonPath('data.is_guest', true)
            ->assertJsonPath('data.cart_token', $token)
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.checkout_ready', true);

        $itemId = Cart::query()->where('token', $token)->firstOrFail()->items()->sole()->id;

        $this->withHeaders(['X-Cart-Token' => $token])
            ->patchJson("/api/cart/items/{$itemId}", ['quantity' => 3])
            ->assertOk()
            ->assertJsonPath('data.items.0.quantity', 3);

        $this->withHeaders(['X-Cart-Token' => $token])
            ->deleteJson("/api/cart/items/{$itemId}")
            ->assertOk()
            ->assertJsonPath('data.items', []);
    }

    public function test_unknown_cart_token_returns_404(): void
    {
        $this->withHeaders(['X-Cart-Token' => (string) Str::uuid()])
            ->getJson('/api/cart')
            ->assertNotFound()
            ->assertJsonPath('code', 'CART_NOT_FOUND');
    }

    public function test_malformed_cart_token_returns_422(): void
    {
        $this->withHeaders(['X-Cart-Token' => 'not-a-uuid'])
            ->getJson('/api/cart')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CART_TOKEN_INVALID');
    }

    public function test_mutating_an_item_without_a_token_returns_404(): void
    {
        $this->patchJson('/api/cart/items/1', ['quantity' => 1])
            ->assertNotFound()
            ->assertJsonPath('code', 'CART_NOT_FOUND');
    }

    public function test_guest_cannot_see_another_guests_items(): void
    {
        $product = Product::factory()->withStock(5)->create();
        [$tokenA] = $this->guestCart();
        $this->withHeaders(['X-Cart-Token' => $tokenA])
            ->postJson('/api/cart/items', ['product_id' => $product->id])
            ->assertCreated();

        [$tokenB] = $this->guestCart();
        $this->withHeaders(['X-Cart-Token' => $tokenB])
            ->getJson('/api/cart')
            ->assertJsonPath('data.items', []);
    }

    public function test_guest_can_apply_a_promotion_to_their_cart(): void
    {
        [$token] = $this->guestCart();
        $product = Product::factory()->priced('120.00')->withStock(5)->create();
        Promotion::factory()->percentage(20, 50)->minCart(100)->create(['code' => 'SUMMER20']);

        $this->withHeaders(['X-Cart-Token' => $token])
            ->postJson('/api/cart/items', ['product_id' => $product->id])
            ->assertCreated();

        $this->withHeaders(['X-Cart-Token' => $token])
            ->postJson('/api/cart/promotion', ['code' => 'SUMMER20'])
            ->assertOk()
            ->assertJsonPath('data.promotion.code', 'SUMMER20')
            ->assertJsonPath('data.discount', '24.00');
    }

    public function test_authenticated_request_with_a_guest_token_merges_the_cart(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->priced('15.00')->withStock(5)->create();
        [$token] = $this->guestCart();

        $this->withHeaders(['X-Cart-Token' => $token])
            ->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => 2])
            ->assertCreated();

        Sanctum::actingAs($user);

        $this->withHeaders(['X-Cart-Token' => $token])
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.is_guest', false)
            ->assertJsonPath('data.cart_token', null)
            ->assertJsonPath('data.items.0.quantity', 2);

        $this->assertDatabaseMissing('carts', ['token' => $token]);
    }

    public function test_idle_guest_cart_is_gone_on_the_next_request(): void
    {
        $token = (string) Str::uuid();
        $cart = Cart::factory()->guest()->create(['token' => $token]);
        Cart::query()->whereKey($cart->id)->update(['updated_at' => now()->subDays(31)]);

        $this->withHeaders(['X-Cart-Token' => $token])
            ->getJson('/api/cart')
            ->assertNotFound()
            ->assertJsonPath('code', 'CART_NOT_FOUND');

        $this->assertDatabaseMissing('carts', ['id' => $cart->id]);
    }

    public function test_using_a_guest_cart_slides_idle_expiry(): void
    {
        $token = (string) Str::uuid();
        $cart = Cart::factory()->guest()->create(['token' => $token]);
        Cart::query()->whereKey($cart->id)->update(['updated_at' => now()->subDays(29)]);

        $this->withHeaders(['X-Cart-Token' => $token])
            ->getJson('/api/cart')
            ->assertOk()
            ->assertJsonPath('data.cart_token', $token);

        Carbon::setTestNow(now()->addDays(2));

        $this->withHeaders(['X-Cart-Token' => $token])
            ->getJson('/api/cart')
            ->assertOk();

        Carbon::setTestNow();
    }

    public function test_authenticated_carts_are_not_pruned_when_idle(): void
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->for($user)->create(['updated_at' => now()->subDays(40)]);

        $this->artisan('cart:expire-reservations')->assertSuccessful();

        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
    }
}
