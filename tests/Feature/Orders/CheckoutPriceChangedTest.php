<?php

namespace Tests\Feature\Orders;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutPriceChangedTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
        Sanctum::actingAs($this->customer);
    }

    public function test_checkout_requires_expected_total(): void
    {
        $this->seedCart('50.00', 1, 5);

        $this->postJson('/api/checkout', $this->shippingPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['expected_total']);

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(1, CartItem::query()->count());
    }

    public function test_matching_expected_total_places_the_order(): void
    {
        $this->seedCart('50.00', 2, 5);

        $this->checkout(['expected_total' => '100.00'])
            ->assertCreated()
            ->assertJsonPath('data.total', '100.00')
            ->assertJsonPath('data.items.0.unit_price', '50.00');
    }

    public function test_expected_total_compares_in_cents_so_scale_does_not_matter(): void
    {
        $this->seedCart('50.00', 1, 5);

        $this->checkout(['expected_total' => '50'])
            ->assertCreated()
            ->assertJsonPath('data.total', '50.00');
    }

    public function test_stale_expected_total_does_not_place_or_take_stock(): void
    {
        [$product] = $this->seedCart('50.00', 2, 7);

        $product->update(['price' => '80.00']);

        $this->checkout(['expected_total' => '100.00'])
            ->assertConflict()
            ->assertJsonPath('code', 'PRICE_CHANGED')
            ->assertJsonPath('details.expected_total', '100.00')
            ->assertJsonPath('details.current_total', '160.00')
            ->assertJsonPath('details.current_subtotal', '160.00')
            ->assertJsonPath('details.current_discount', '0.00');

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(7, $product->fresh()->stock_quantity);
        $this->assertSame(1, CartItem::query()->count());
    }

    public function test_client_retries_with_the_new_total_after_price_changed(): void
    {
        [$product] = $this->seedCart('50.00', 1, 4);

        $product->update(['price' => '80.00']);

        $this->checkout(['expected_total' => '50.00'])->assertConflict();

        $this->checkout(['expected_total' => '80.00'])
            ->assertCreated()
            ->assertJsonPath('data.total', '80.00')
            ->assertJsonPath('data.items.0.unit_price', '80.00');

        $this->assertSame(3, $product->fresh()->stock_quantity);
        $this->assertSame(0, CartItem::query()->count());
    }

    public function test_expected_total_is_confirmation_not_the_amount_charged(): void
    {
        $this->seedCart('19.99', 1, 3);

        $this->checkout([
            'expected_total' => '19.99',
            'total' => '0.01',
            'subtotal' => '0.01',
            'price' => '0.01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.total', '19.99');
    }

    public function test_expected_total_must_match_the_discounted_payable_total(): void
    {
        $promotion = Promotion::factory()->percentage(20)->minCart(0)->create(['code' => 'SAVE20']);
        $this->seedCart('100.00', 1, 5);
        Cart::query()->where('user_id', $this->customer->id)->update(['promotion_id' => $promotion->id]);

        $this->checkout(['expected_total' => '100.00'])
            ->assertConflict()
            ->assertJsonPath('code', 'PRICE_CHANGED')
            ->assertJsonPath('details.expected_total', '100.00')
            ->assertJsonPath('details.current_total', '80.00')
            ->assertJsonPath('details.current_discount', '20.00');

        $this->checkout(['expected_total' => '80.00'])
            ->assertCreated()
            ->assertJsonPath('data.total', '80.00')
            ->assertJsonPath('data.discount', '20.00');
    }

    public function test_idempotent_replay_does_not_require_the_current_catalog_total(): void
    {
        [$product] = $this->seedCart('50.00', 1, 5);

        $this->checkout([], ['Idempotency-Key' => 'price-confirm-once'])
            ->assertCreated()
            ->assertJsonPath('data.total', '50.00');

        $product->update(['price' => '999.00']);

        $this->checkout([
            'expected_total' => '0.00',
        ], ['Idempotency-Key' => 'price-confirm-once'])
            ->assertOk()
            ->assertJsonPath('message', 'Order already placed.')
            ->assertJsonPath('data.total', '50.00')
            ->assertJsonPath('data.items.0.unit_price', '50.00');

        $this->assertSame(1, Order::query()->count());
        $this->assertSame(4, $product->fresh()->stock_quantity);
    }

    /**
     * @return array{0: Product, 1: CartItem}
     */
    private function seedCart(string $price, int $quantity, int $stock): array
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => $this->customer->id]);
        $product = Product::factory()->priced($price)->withStock($stock)->create();
        $item = CartItem::factory()->for($cart)->for($product)->create(['quantity' => $quantity]);

        return [$product, $item];
    }
}
