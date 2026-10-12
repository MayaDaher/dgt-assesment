<?php

namespace Tests\Feature\Promotions;

use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PromotionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = User::factory()->create();
        Sanctum::actingAs($this->customer);
    }

    private function cartWorth(string $price, int $quantity = 1, ?User $user = null): CartItem
    {
        $cart = Cart::query()->firstOrCreate(['user_id' => ($user ?? $this->customer)->id]);
        $product = Product::factory()->priced($price)->withStock(100)->create();

        return CartItem::factory()->for($cart)->for($product)->create(['quantity' => $quantity]);
    }

    private function summer20(): Promotion
    {
        return Promotion::factory()->percentage(20, 50)->minCart(100)->create(['code' => 'SUMMER20']);
    }

    private function validateCode(string $code): TestResponse
    {
        return $this->postJson('/api/promotions/validate', ['code' => $code]);
    }

    public function test_guests_can_preview_promotions_against_their_cart(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/promotions/validate', ['code' => 'X'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CART_EMPTY');
    }

    public function test_summer20_example_from_the_brief(): void
    {
        $this->summer20();

        $this->cartWorth('100.00');
        $this->validateCode('SUMMER20')
            ->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonPath('data.subtotal', '100.00')
            ->assertJsonPath('data.discount', '20.00')
            ->assertJsonPath('data.total', '80.00');

        $this->cartWorth('200.00');
        $this->validateCode('SUMMER20')
            ->assertOk()
            ->assertJsonPath('data.subtotal', '300.00')
            ->assertJsonPath('data.discount', '50.00')
            ->assertJsonPath('data.total', '250.00');
    }

    public function test_minimum_cart_amount_not_met(): void
    {
        $this->summer20();
        $this->cartWorth('99.99');

        $this->validateCode('SUMMER20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PROMOTION_MINIMUM_NOT_MET')
            ->assertJsonPath('details.min_cart_amount', '100.00')
            ->assertJsonPath('details.cart_subtotal', '99.99')
            ->assertJsonPath('details.amount_remaining', '0.01');
    }

    public function test_fixed_discount_never_exceeds_the_subtotal(): void
    {
        Promotion::factory()->fixed(100)->create(['code' => 'BIG']);
        $this->cartWorth('30.00');

        $this->validateCode('BIG')
            ->assertOk()
            ->assertJsonPath('data.discount', '30.00')
            ->assertJsonPath('data.total', '0.00');
    }

    public function test_code_is_case_insensitive_and_trimmed(): void
    {
        $this->summer20();
        $this->cartWorth('150.00');

        $this->validateCode('  summer20 ')->assertOk()->assertJsonPath('data.code', 'SUMMER20');
    }

    public function test_unknown_code_is_invalid(): void
    {
        $this->cartWorth('50.00');

        $this->validateCode('NOPE')
            ->assertUnprocessable()
            ->assertExactJson([
                'success' => false,
                'message' => 'The promotion code is invalid.',
                'code' => 'PROMOTION_INVALID',
            ]);
    }

    public function test_deleted_code_is_invalid(): void
    {
        Promotion::factory()->create(['code' => 'GONE'])->delete();
        $this->cartWorth('50.00');

        $this->validateCode('GONE')->assertUnprocessable()->assertJsonPath('code', 'PROMOTION_INVALID');
    }

    public function test_inactive_code(): void
    {
        Promotion::factory()->inactive()->create(['code' => 'OFF']);
        $this->cartWorth('50.00');

        $this->validateCode('OFF')->assertUnprocessable()->assertJsonPath('code', 'PROMOTION_INACTIVE');
    }

    public function test_code_not_started_yet_and_start_boundary(): void
    {
        $promotion = Promotion::factory()->notStarted()->create(['code' => 'SOON']);
        $this->cartWorth('50.00');

        $this->validateCode('SOON')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PROMOTION_NOT_STARTED')
            ->assertJsonPath('details.starts_at', $promotion->starts_at->toIso8601String());

        $this->travelTo($promotion->starts_at);
        $this->validateCode('SOON')->assertOk();
    }

    public function test_expired_code_and_end_boundary(): void
    {
        $promotion = Promotion::factory()->create([
            'code' => 'LAST',
            'starts_at' => now()->subWeek(),
            'ends_at' => now()->addDay(),
        ]);
        $this->cartWorth('50.00');

        $this->travelTo($promotion->ends_at);
        $this->validateCode('LAST')->assertOk();

        $this->travelTo($promotion->ends_at->copy()->addSecond());
        $this->validateCode('LAST')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PROMOTION_EXPIRED')
            ->assertJsonPath('message', 'This promotion code has expired.');
    }

    public function test_global_usage_limit_reached(): void
    {
        Promotion::factory()->usageLimit(5, used: 5)->create(['code' => 'GONEFAST']);
        Promotion::factory()->usageLimit(5, used: 4)->create(['code' => 'LASTONE']);
        $this->cartWorth('50.00');

        $this->validateCode('GONEFAST')->assertUnprocessable()->assertJsonPath('code', 'PROMOTION_USAGE_LIMIT_REACHED');
        $this->validateCode('LASTONE')->assertOk();
    }

    public function test_per_customer_usage_limit_reached(): void
    {
        $promotion = Promotion::factory()->perCustomerLimit(2)->create(['code' => 'TWICE']);
        PromotionUsage::create(['promotion_id' => $promotion->id, 'user_id' => $this->customer->id]);
        $this->cartWorth('50.00');

        $this->validateCode('TWICE')->assertOk();

        PromotionUsage::create(['promotion_id' => $promotion->id, 'user_id' => $this->customer->id]);

        $this->validateCode('TWICE')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PROMOTION_CUSTOMER_LIMIT_REACHED')
            ->assertJsonPath('details', ['per_customer_limit' => 2, 'times_used' => 2]);

        $other = User::factory()->create();
        $this->cartWorth('50.00', user: $other);
        Sanctum::actingAs($other);
        $this->validateCode('TWICE')->assertOk();
    }

    public function test_rules_are_checked_in_order(): void
    {
        Promotion::factory()->inactive()->expired()->usageLimit(1, used: 1)->minCart(1000)->create(['code' => 'BROKEN']);
        $this->cartWorth('10.00');

        $this->validateCode('BROKEN')->assertJsonPath('code', 'PROMOTION_INACTIVE');
    }

    public function test_empty_cart_cannot_use_a_code(): void
    {
        $this->summer20();

        $this->validateCode('SUMMER20')->assertUnprocessable()->assertJsonPath('code', 'CART_EMPTY');
        $this->postJson('/api/cart/promotion', ['code' => 'SUMMER20'])->assertUnprocessable()->assertJsonPath('code', 'CART_EMPTY');
    }

    public function test_code_is_required(): void
    {
        $this->postJson('/api/promotions/validate', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);

        $this->postJson('/api/cart/promotion', ['code' => str_repeat('A', 51)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code']);
    }

    public function test_validate_does_not_apply_the_code(): void
    {
        $this->summer20();
        $this->cartWorth('150.00');

        $this->validateCode('SUMMER20')->assertOk();

        $this->assertNull(Cart::query()->where('user_id', $this->customer->id)->value('promotion_id'));
    }

    public function test_customer_can_apply_replace_and_remove_a_code(): void
    {
        $this->summer20();
        Promotion::factory()->fixed(10)->create(['code' => 'TENOFF']);
        $this->cartWorth('150.00');

        $this->postJson('/api/cart/promotion', ['code' => 'summer20'])
            ->assertOk()
            ->assertJsonPath('message', 'Promotion code applied.')
            ->assertJsonPath('data.promotion.code', 'SUMMER20')
            ->assertJsonPath('data.promotion.is_valid', true)
            ->assertJsonPath('data.promotion.error', null)
            ->assertJsonPath('data.subtotal', '150.00')
            ->assertJsonPath('data.discount', '30.00')
            ->assertJsonPath('data.total', '120.00')
            ->assertJsonPath('data.checkout_ready', true);

        $this->postJson('/api/cart/promotion', ['code' => 'TENOFF'])
            ->assertOk()
            ->assertJsonPath('data.promotion.code', 'TENOFF')
            ->assertJsonPath('data.discount', '10.00');

        $this->deleteJson('/api/cart/promotion')
            ->assertOk()
            ->assertJsonPath('data.promotion', null)
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.total', '150.00');
    }

    public function test_failed_apply_keeps_the_previous_code(): void
    {
        $this->summer20();
        Promotion::factory()->expired()->create(['code' => 'OLD']);
        $this->cartWorth('150.00');

        $this->postJson('/api/cart/promotion', ['code' => 'SUMMER20'])->assertOk();
        $this->postJson('/api/cart/promotion', ['code' => 'OLD'])->assertUnprocessable()->assertJsonPath('code', 'PROMOTION_EXPIRED');

        $this->getJson('/api/cart')->assertJsonPath('data.promotion.code', 'SUMMER20');
    }

    public function test_applied_code_is_revalidated_when_the_cart_changes(): void
    {
        $this->summer20();
        $keep = $this->cartWorth('60.00');
        $remove = $this->cartWorth('60.00');

        $this->postJson('/api/cart/promotion', ['code' => 'SUMMER20'])
            ->assertJsonPath('data.discount', '24.00');

        $this->deleteJson("/api/cart/items/{$remove->id}")
            ->assertOk()
            ->assertJsonPath('data.promotion.code', 'SUMMER20')
            ->assertJsonPath('data.promotion.is_valid', false)
            ->assertJsonPath('data.promotion.error.code', 'PROMOTION_MINIMUM_NOT_MET')
            ->assertJsonPath('data.promotion.error.details.amount_remaining', '40.00')
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.total', '60.00')
            ->assertJsonPath('data.has_issues', true)
            ->assertJsonPath('data.checkout_ready', false);

        $this->patchJson("/api/cart/items/{$keep->id}", ['quantity' => 2])
            ->assertJsonPath('data.promotion.is_valid', true)
            ->assertJsonPath('data.discount', '24.00')
            ->assertJsonPath('data.checkout_ready', true);
    }

    public function test_applied_code_that_expires_stops_discounting(): void
    {
        $promotion = Promotion::factory()->create(['code' => 'SHORT', 'ends_at' => now()->addHour()]);
        $this->cartWorth('100.00');
        $this->postJson('/api/cart/promotion', ['code' => 'SHORT'])->assertJsonPath('data.discount', '10.00');

        $this->travelTo($promotion->ends_at->copy()->addMinute());

        $this->getJson('/api/cart')
            ->assertJsonPath('data.promotion.error.code', 'PROMOTION_EXPIRED')
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.checkout_ready', false);
    }

    public function test_discount_only_uses_purchasable_lines(): void
    {
        Promotion::factory()->percentage(10)->create(['code' => 'TEN']);
        $this->cartWorth('100.00');
        $broken = $this->cartWorth('900.00');
        $broken->product->update(['status' => ProductStatus::Inactive]);

        $this->postJson('/api/cart/promotion', ['code' => 'TEN'])
            ->assertOk()
            ->assertJsonPath('data.subtotal', '100.00')
            ->assertJsonPath('data.discount', '10.00');
    }

    public function test_clearing_the_cart_removes_the_code(): void
    {
        $this->summer20();
        $this->cartWorth('150.00');
        $this->postJson('/api/cart/promotion', ['code' => 'SUMMER20'])->assertOk();

        $this->deleteJson('/api/cart')->assertOk()->assertJsonPath('data.promotion', null);
    }

    public function test_code_checks_are_rate_limited(): void
    {
        $this->cartWorth('50.00');

        for ($i = 0; $i < 10; $i++) {
            $this->validateCode("GUESS{$i}")->assertUnprocessable();
        }

        $this->validateCode('GUESS10')
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS');
    }
}
