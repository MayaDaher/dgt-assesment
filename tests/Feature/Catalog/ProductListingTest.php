<?php

namespace Tests\Feature\Catalog;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_paginated_list_with_meta_and_links(): void
    {
        Product::factory()->count(20)->create();

        $this->getJson('/api/products?per_page=5&page=2')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonPath('meta.total', 20)
            ->assertJsonPath('meta.last_page', 4)
            ->assertJsonStructure([
                'success', 'message',
                'data' => [['id', 'name', 'sku', 'description', 'price', 'stock_quantity', 'in_stock', 'status', 'category' => ['id', 'name', 'slug'], 'created_at', 'updated_at']],
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
            ]);
    }

    public function test_default_page_size_is_15_and_links_keep_the_query_string(): void
    {
        Product::factory()->count(16)->create();

        $response = $this->getJson('/api/products?sort=price')
            ->assertOk()
            ->assertJsonCount(15, 'data');

        $this->assertStringContainsString('sort=price', $response->json('links.next'));
    }

    public function test_it_hides_inactive_and_deleted_products(): void
    {
        $visible = Product::factory()->create();
        Product::factory()->inactive()->create();
        Product::factory()->create()->delete();

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $visible->id);
    }

    public function test_it_searches_by_name(): void
    {
        $match = Product::factory()->create(['name' => 'Wireless Headphones']);
        Product::factory()->create(['name' => 'Office Chair']);

        $this->getJson('/api/products?search=headphones')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_it_filters_by_price_range(): void
    {
        Product::factory()->priced(10)->create();
        $mid = Product::factory()->priced(50)->create();
        Product::factory()->priced(100)->create();

        $this->getJson('/api/products?min_price=20&max_price=50')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mid->id)
            ->assertJsonPath('data.0.price', '50.00');

        $this->getJson('/api/products?min_price=50')->assertJsonCount(2, 'data');
        $this->getJson('/api/products?max_price=10')->assertJsonCount(1, 'data');
    }

    public function test_it_filters_by_availability(): void
    {
        $inStock = Product::factory()->withStock(3)->create();
        $outOfStock = Product::factory()->outOfStock()->create();

        $this->getJson('/api/products?availability=in_stock')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inStock->id)
            ->assertJsonPath('data.0.in_stock', true);

        $this->getJson('/api/products?availability=out_of_stock')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $outOfStock->id)
            ->assertJsonPath('data.0.in_stock', false);
    }

    public function test_it_filters_by_category_including_subcategories(): void
    {
        $electronics = Category::factory()->create(['slug' => 'electronics']);
        $phones = Category::factory()->childOf($electronics)->create(['slug' => 'phones']);
        $tv = Product::factory()->for($electronics)->create();
        $phone = Product::factory()->for($phones)->create();
        Product::factory()->create();

        $ids = collect($this->getJson('/api/products?category=electronics')->assertOk()->json('data'))->pluck('id');
        $this->assertEqualsCanonicalizing([$tv->id, $phone->id], $ids->all());

        $this->getJson('/api/products?category=phones')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $phone->id);
    }

    public function test_it_combines_filters(): void
    {
        $category = Category::factory()->create(['slug' => 'audio']);
        $match = Product::factory()->for($category)->priced(80)->withStock(2)->create(['name' => 'Studio Headphones']);
        Product::factory()->for($category)->priced(80)->outOfStock()->create(['name' => 'Gaming Headphones']);
        Product::factory()->for($category)->priced(500)->create(['name' => 'Premium Headphones']);
        Product::factory()->priced(80)->create(['name' => 'Cheap Headphones']);

        $this->getJson('/api/products?search=headphones&category=audio&max_price=100&availability=in_stock')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_it_sorts_by_allowed_fields(): void
    {
        $cheap = Product::factory()->priced(5)->create(['name' => 'Banana', 'created_at' => now()->subDays(2)]);
        $pricey = Product::factory()->priced(500)->create(['name' => 'Apple', 'created_at' => now()->subDay()]);
        $mid = Product::factory()->priced(50)->create(['name' => 'Cherry', 'created_at' => now()]);

        $cases = [
            'price' => [$cheap, $mid, $pricey],
            '-price' => [$pricey, $mid, $cheap],
            'name' => [$pricey, $cheap, $mid],
            '-name' => [$mid, $cheap, $pricey],
            'created_at' => [$cheap, $pricey, $mid],
            '-created_at' => [$mid, $pricey, $cheap],
        ];

        foreach ($cases as $sort => $expected) {
            $ids = collect($this->getJson("/api/products?sort={$sort}")->assertOk()->json('data'))->pluck('id')->all();
            $this->assertSame(collect($expected)->pluck('id')->all(), $ids, "Sort [{$sort}] returned the wrong order.");
        }

        $defaultIds = collect($this->getJson('/api/products')->json('data'))->pluck('id')->all();
        $this->assertSame([$mid->id, $pricey->id, $cheap->id], $defaultIds, 'Default sort should be newest first.');
    }

    public function test_it_validates_query_parameters(): void
    {
        $this->getJson('/api/products?sort=stock_quantity;drop table products&availability=maybe&per_page=500&min_price=-1&category=nope&page=0')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['sort', 'availability', 'per_page', 'min_price', 'category', 'page']);
    }

    public function test_max_price_cannot_be_lower_than_min_price(): void
    {
        $this->getJson('/api/products?min_price=100&max_price=10')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['max_price']);
    }

    public function test_inactive_category_cannot_be_used_as_filter(): void
    {
        Category::factory()->inactive()->create(['slug' => 'hidden']);

        $this->getJson('/api/products?category=hidden')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category']);
    }

    public function test_listing_does_not_run_n_plus_one_queries(): void
    {
        Product::factory()->count(30)->create();

        DB::enableQueryLog();
        $this->getJson('/api/products?per_page=30')->assertOk()->assertJsonCount(30, 'data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(3, $queries, "Expected count + products + categories queries, got {$queries}.");
    }

    public function test_listing_is_public(): void
    {
        $this->getJson('/api/products')->assertOk();
    }
}
