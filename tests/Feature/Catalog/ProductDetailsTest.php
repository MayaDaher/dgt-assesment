<?php

namespace Tests\Feature\Catalog;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDetailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_product_details_with_category(): void
    {
        $product = Product::factory()->priced('999.90')->withStock(7)->create(['sku' => 'phn-001']);

        $this->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.sku', 'PHN-001')
            ->assertJsonPath('data.price', '999.90')
            ->assertJsonPath('data.stock_quantity', 7)
            ->assertJsonPath('data.in_stock', true)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.category.id', $product->category_id);
    }

    public function test_product_without_category_returns_null_category(): void
    {
        $product = Product::factory()->withoutCategory()->create();

        $this->getJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.category', null);
    }

    public function test_missing_product_returns_404(): void
    {
        $this->getJson('/api/products/999999')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Product not found.',
                'code' => 'RESOURCE_NOT_FOUND',
            ]);
    }

    public function test_inactive_and_deleted_products_return_404(): void
    {
        $inactive = Product::factory()->inactive()->create();
        $deleted = Product::factory()->create();
        $deleted->delete();

        $this->getJson("/api/products/{$inactive->id}")->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        $this->getJson("/api/products/{$deleted->id}")->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_non_numeric_id_returns_404(): void
    {
        $this->getJson('/api/products/abc')->assertNotFound();
    }
}
