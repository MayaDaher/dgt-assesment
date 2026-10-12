<?php

namespace Tests\Feature\Admin;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminProductTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_guests_and_customers_cannot_manage_products(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/products')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/admin/products')->assertForbidden();
    }

    public function test_admin_can_create_an_inactive_product_hidden_from_the_storefront(): void
    {
        $category = Category::factory()->create();

        $response = $this->postJson('/api/admin/products', [
            'name' => 'Hidden Gadget',
            'sku' => 'adm-001',
            'price' => '19.99',
            'stock_quantity' => 4,
            'status' => ProductStatus::Inactive->value,
            'category_id' => $category->id,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.name', 'Hidden Gadget')
            ->assertJsonPath('data.sku', 'ADM-001')
            ->assertJsonPath('data.status', ProductStatus::Inactive->value)
            ->assertJsonPath('data.price', '19.99');

        $id = $response->json('data.id');

        $this->getJson('/api/admin/products')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/admin/products/{$id}")->assertOk()->assertJsonPath('data.status', 'inactive');
        $this->getJson('/api/products')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/products/{$id}")->assertNotFound();
    }

    public function test_admin_can_update_soft_delete_and_restore_a_product(): void
    {
        $product = Product::factory()->create(['name' => 'Before', 'sku' => 'KEEP-1']);

        $this->patchJson("/api/admin/products/{$product->id}", [
            'name' => 'After',
            'price' => '12.50',
        ])->assertOk()->assertJsonPath('data.name', 'After')->assertJsonPath('data.price', '12.50');

        $this->deleteJson("/api/admin/products/{$product->id}")->assertOk();
        $this->assertSoftDeleted($product);
        $this->getJson("/api/admin/products/{$product->id}")->assertNotFound();

        $this->postJson("/api/admin/products/{$product->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.name', 'After');

        $this->assertNotSoftDeleted($product);
    }

    public function test_sku_must_be_unique(): void
    {
        Product::factory()->create(['sku' => 'TAKEN']);

        $this->postJson('/api/admin/products', [
            'name' => 'Copy',
            'sku' => 'taken',
            'price' => '1.00',
            'stock_quantity' => 1,
        ])->assertUnprocessable()->assertJsonValidationErrors(['sku']);
    }

    public function test_client_cannot_send_a_negative_price_or_stock(): void
    {
        $this->postJson('/api/admin/products', [
            'name' => 'Bad',
            'sku' => 'BAD-1',
            'price' => '-1',
            'stock_quantity' => -2,
        ])->assertUnprocessable()->assertJsonValidationErrors(['price', 'stock_quantity']);
    }
}
