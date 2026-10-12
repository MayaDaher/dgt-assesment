<?php

namespace Tests\Feature\Promotions;

use App\Enums\ProductStatus;
use App\Enums\PromotionScope;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScopedPromotionTest extends TestCase
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
     * @return array{0: Category, 1: Category, 2: Product, 3: Product, 4: Product}
     */
    private function catalog(): array
    {
        $electronics = Category::factory()->create(['name' => 'Electronics', 'slug' => 'electronics']);
        $audio = Category::factory()->create(['name' => 'Audio', 'slug' => 'electronics-audio', 'parent_id' => $electronics->id]);
        $phones = Category::factory()->create(['name' => 'Phones', 'slug' => 'electronics-phones', 'parent_id' => $electronics->id]);
        $fashion = Category::factory()->create(['name' => 'Fashion', 'slug' => 'fashion']);

        $headphones = Product::factory()->for($audio)->priced('100.00')->withStock(10)->create(['name' => 'Headphones']);
        $phone = Product::factory()->for($phones)->priced('200.00')->withStock(10)->create(['name' => 'Phone']);
        $jacket = Product::factory()->for($fashion)->priced('80.00')->withStock(10)->create(['name' => 'Jacket']);

        return [$electronics, $audio, $headphones, $phone, $jacket];
    }

    private function add(Product $product, int $quantity = 1): CartItem
    {
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'quantity' => $quantity])->assertSuccessful();

        return Cart::query()->where('user_id', $this->customer->id)->firstOrFail()
            ->items()->where('product_id', $product->id)->firstOrFail();
    }

    public function test_product_scoped_percentage_only_discounts_listed_products(): void
    {
        [, , $headphones, , $jacket] = $this->catalog();
        Promotion::factory()->percentage(20)->forProducts($headphones)->create(['code' => 'HEAD20']);

        $this->add($headphones);
        $this->add($jacket);

        $this->postJson('/api/promotions/validate', ['code' => 'HEAD20'])
            ->assertOk()
            ->assertJsonPath('data.scope', PromotionScope::Products->value)
            ->assertJsonPath('data.subtotal', '180.00')
            ->assertJsonPath('data.eligible_subtotal', '100.00')
            ->assertJsonPath('data.discount', '20.00')
            ->assertJsonPath('data.total', '160.00');

        $cart = $this->postJson('/api/cart/promotion', ['code' => 'HEAD20'])->assertOk();
        $lines = collect($cart->json('data.items'));

        $this->assertTrue($lines->firstWhere('product.id', $headphones->id)['promotion_eligible']);
        $this->assertSame('20.00', $lines->firstWhere('product.id', $headphones->id)['discount']);
        $this->assertFalse($lines->firstWhere('product.id', $jacket->id)['promotion_eligible']);
        $this->assertSame('0.00', $lines->firstWhere('product.id', $jacket->id)['discount']);
        $this->assertSame('20.00', $cart->json('data.discount'));
        $this->assertSame('160.00', $cart->json('data.total'));
    }

    public function test_category_scope_includes_direct_subcategories(): void
    {
        [$electronics, , $headphones, $phone, $jacket] = $this->catalog();
        Promotion::factory()->percentage(10)->forCategories($electronics)->create(['code' => 'TECH10']);

        $this->add($headphones);
        $this->add($phone);
        $this->add($jacket);

        $this->postJson('/api/promotions/validate', ['code' => 'TECH10'])
            ->assertOk()
            ->assertJsonPath('data.eligible_subtotal', '300.00')
            ->assertJsonPath('data.discount', '30.00')
            ->assertJsonPath('data.total', '350.00');
    }

    public function test_child_category_scope_does_not_include_siblings(): void
    {
        [, $audio, $headphones, $phone] = $this->catalog();
        Promotion::factory()->percentage(10)->forCategories($audio)->create(['code' => 'AUDIO10']);

        $this->add($headphones);
        $this->add($phone);

        $this->postJson('/api/promotions/validate', ['code' => 'AUDIO10'])
            ->assertOk()
            ->assertJsonPath('data.eligible_subtotal', '100.00')
            ->assertJsonPath('data.discount', '10.00')
            ->assertJsonPath('data.total', '290.00');
    }

    public function test_no_matching_items_returns_not_applicable(): void
    {
        [, $audio, , , $jacket] = $this->catalog();
        Promotion::factory()->percentage(10)->forCategories($audio)->create(['code' => 'AUDIO10']);

        $this->add($jacket);

        $this->postJson('/api/promotions/validate', ['code' => 'AUDIO10'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PROMOTION_NOT_APPLICABLE')
            ->assertJsonPath('details.scope', 'categories');
    }

    public function test_minimum_is_measured_on_the_eligible_subtotal(): void
    {
        [, $audio, $headphones, , $jacket] = $this->catalog();
        Promotion::factory()->percentage(10)->minCart(150)->forCategories($audio)->create(['code' => 'AUDIO10']);

        $this->add($headphones);
        $this->add($jacket);

        $this->postJson('/api/promotions/validate', ['code' => 'AUDIO10'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PROMOTION_MINIMUM_NOT_MET')
            ->assertJsonPath('details.eligible_subtotal', '100.00')
            ->assertJsonPath('details.amount_remaining', '50.00');

        $this->add($headphones);
        $this->postJson('/api/promotions/validate', ['code' => 'AUDIO10'])
            ->assertOk()
            ->assertJsonPath('data.eligible_subtotal', '200.00')
            ->assertJsonPath('data.discount', '20.00');
    }

    public function test_fixed_discount_never_exceeds_the_eligible_subtotal(): void
    {
        [, , $headphones, , $jacket] = $this->catalog();
        Promotion::factory()->fixed(50)->forProducts($headphones)->create(['code' => 'HEAD50']);

        $this->add($headphones);
        $this->add($jacket);

        $this->postJson('/api/promotions/validate', ['code' => 'HEAD50'])
            ->assertOk()
            ->assertJsonPath('data.discount', '50.00')
            ->assertJsonPath('data.total', '130.00');

        $headphones->update(['price' => '30.00']);

        $this->postJson('/api/promotions/validate', ['code' => 'HEAD50'])
            ->assertOk()
            ->assertJsonPath('data.eligible_subtotal', '30.00')
            ->assertJsonPath('data.discount', '30.00')
            ->assertJsonPath('data.total', '80.00');
    }

    public function test_unavailable_matching_product_does_not_count(): void
    {
        [, , $headphones, , $jacket] = $this->catalog();
        Promotion::factory()->percentage(10)->forProducts($headphones)->create(['code' => 'HEAD10']);

        $this->add($headphones);
        $this->add($jacket);
        $headphones->update(['status' => ProductStatus::Inactive]);

        $this->postJson('/api/promotions/validate', ['code' => 'HEAD10'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'PROMOTION_NOT_APPLICABLE');
    }

    public function test_removing_the_last_scoped_item_invalidates_the_applied_code(): void
    {
        [, , $headphones, , $jacket] = $this->catalog();
        Promotion::factory()->percentage(10)->forProducts($headphones)->create(['code' => 'HEAD10']);

        $headItem = $this->add($headphones);
        $this->add($jacket);
        $this->postJson('/api/cart/promotion', ['code' => 'HEAD10'])->assertOk();

        $this->deleteJson("/api/cart/items/{$headItem->id}")
            ->assertOk()
            ->assertJsonPath('data.promotion.code', 'HEAD10')
            ->assertJsonPath('data.promotion.is_valid', false)
            ->assertJsonPath('data.promotion.error.code', 'PROMOTION_NOT_APPLICABLE')
            ->assertJsonPath('data.discount', '0.00')
            ->assertJsonPath('data.checkout_ready', false);
    }

    public function test_checkout_charges_only_the_scoped_discount(): void
    {
        [, , $headphones, , $jacket] = $this->catalog();
        $promotion = Promotion::factory()->percentage(10)->forProducts($headphones)->create(['code' => 'HEAD10']);

        $this->add($headphones);
        $this->add($jacket);
        $this->postJson('/api/cart/promotion', ['code' => 'HEAD10'])->assertOk();

        $this->postJson('/api/checkout')
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '180.00')
            ->assertJsonPath('data.discount', '10.00')
            ->assertJsonPath('data.total', '170.00')
            ->assertJsonPath('data.promotion.code', 'HEAD10');

        $this->assertSame(1, $promotion->fresh()->used_count);
    }

    public function test_order_wide_promotions_still_use_the_full_purchasable_subtotal(): void
    {
        [, , $headphones, , $jacket] = $this->catalog();
        Promotion::factory()->percentage(10)->create(['code' => 'ALL10']);

        $this->add($headphones);
        $this->add($jacket);

        $this->postJson('/api/promotions/validate', ['code' => 'ALL10'])
            ->assertOk()
            ->assertJsonPath('data.scope', PromotionScope::Order->value)
            ->assertJsonPath('data.eligible_subtotal', '180.00')
            ->assertJsonPath('data.discount', '18.00');
    }
}
