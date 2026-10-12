<?php

namespace Tests\Feature\Admin;

use App\Enums\PromotionScope;
use App\Enums\PromotionType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPromotionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_guests_and_customers_cannot_manage_promotions(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/promotions')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/admin/promotions', [
            'code' => 'NOPE',
            'type' => PromotionType::Fixed->value,
            'value' => '5',
        ])->assertForbidden();
    }

    public function test_admin_can_create_update_and_delete_a_promotion(): void
    {
        $created = $this->postJson('/api/admin/promotions', [
            'code' => '  winter15 ',
            'type' => PromotionType::Percentage->value,
            'value' => '15',
            'min_cart_amount' => '50',
            'max_discount_amount' => '20',
            'usage_limit' => 100,
        ]);

        $created
            ->assertCreated()
            ->assertJsonPath('data.code', 'WINTER15')
            ->assertJsonPath('data.type', 'percentage')
            ->assertJsonPath('data.value', '15.00')
            ->assertJsonPath('data.used_count', 0);

        $id = $created->json('data.id');

        $this->patchJson("/api/admin/promotions/{$id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/admin/promotions')->assertOk()->assertJsonPath('data.0.id', $id);

        $this->deleteJson("/api/admin/promotions/{$id}")->assertOk();
        $this->assertSoftDeleted('promotions', ['id' => $id]);
    }

    public function test_used_count_cannot_be_set_from_the_request(): void
    {
        $id = $this->postJson('/api/admin/promotions', [
            'code' => 'SAFE',
            'type' => PromotionType::Fixed->value,
            'value' => '5',
            'used_count' => 99,
        ])->assertCreated()->json('data.id');

        $this->assertSame(0, Promotion::query()->findOrFail($id)->used_count);
    }

    public function test_percentage_cannot_exceed_one_hundred(): void
    {
        $this->postJson('/api/admin/promotions', [
            'code' => 'TOO-MUCH',
            'type' => PromotionType::Percentage->value,
            'value' => '150',
        ])->assertUnprocessable()->assertJsonValidationErrors(['value']);
    }

    public function test_admin_can_create_a_product_scoped_promotion(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/api/admin/promotions', [
            'code' => 'HEAD20',
            'type' => PromotionType::Percentage->value,
            'value' => '20',
            'scope' => PromotionScope::Products->value,
            'product_ids' => [$product->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.scope', 'products')
            ->assertJsonPath('data.product_ids.0', $product->id)
            ->assertJsonPath('data.category_ids', []);

        $this->assertDatabaseHas('product_promotion', [
            'product_id' => $product->id,
        ]);
    }

    public function test_admin_can_create_a_category_scoped_promotion(): void
    {
        $category = Category::factory()->create();

        $id = $this->postJson('/api/admin/promotions', [
            'code' => 'TECH10',
            'type' => PromotionType::Percentage->value,
            'value' => '10',
            'scope' => PromotionScope::Categories->value,
            'category_ids' => [$category->id],
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('category_promotion', [
            'promotion_id' => $id,
            'category_id' => $category->id,
        ]);
    }

    public function test_product_scope_requires_at_least_one_product(): void
    {
        $this->postJson('/api/admin/promotions', [
            'code' => 'EMPTY',
            'type' => PromotionType::Fixed->value,
            'value' => '5',
            'scope' => PromotionScope::Products->value,
        ])->assertUnprocessable()->assertJsonValidationErrors(['product_ids']);
    }

    public function test_order_scope_rejects_product_ids(): void
    {
        $product = Product::factory()->create();

        $this->postJson('/api/admin/promotions', [
            'code' => 'WIDE',
            'type' => PromotionType::Fixed->value,
            'value' => '5',
            'scope' => PromotionScope::Order->value,
            'product_ids' => [$product->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['product_ids']);
    }

    public function test_updating_scope_to_order_clears_restrictions(): void
    {
        $product = Product::factory()->create();
        $promotion = Promotion::factory()->forProducts($product)->create();

        $this->patchJson("/api/admin/promotions/{$promotion->id}", [
            'scope' => PromotionScope::Order->value,
        ])
            ->assertOk()
            ->assertJsonPath('data.scope', 'order')
            ->assertJsonPath('data.product_ids', []);

        $this->assertDatabaseCount('product_promotion', 0);
    }

    public function test_usage_limit_cannot_drop_below_used_count(): void
    {
        $promotion = Promotion::factory()->usageLimit(5, used: 3)->create();

        $this->patchJson("/api/admin/promotions/{$promotion->id}", ['usage_limit' => 1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['usage_limit']);
    }
}
