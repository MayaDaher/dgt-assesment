<?php

namespace Tests\Feature\Catalog;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductListingCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_listing_requests_do_not_query_products_again(): void
    {
        Product::factory()->count(3)->create();

        $this->getJson('/api/products')->assertOk()->assertJsonCount(3, 'data');

        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson('/api/products')->assertOk()->assertJsonCount(3, 'data');

        $productQueries = collect(DB::getQueryLog())->filter(
            fn (array $query) => str_contains(strtolower($query['query']), 'from "products"')
                || str_contains(strtolower($query['query']), 'from `products`')
        );

        $this->assertCount(0, $productQueries);
    }

    public function test_different_pages_are_cached_separately(): void
    {
        Product::factory()->count(3)->create();

        $page1 = $this->getJson('/api/products?per_page=1&page=1')->assertOk()->json('data.0.id');
        $page2 = $this->getJson('/api/products?per_page=1&page=2')->assertOk()->json('data.0.id');

        $this->assertNotSame($page1, $page2);
    }

    public function test_updating_a_product_invalidates_the_listing_cache(): void
    {
        $product = Product::factory()->create(['name' => 'Old Name']);

        $this->getJson('/api/products')->assertJsonPath('data.0.name', 'Old Name');

        $product->update(['name' => 'New Name']);

        $this->getJson('/api/products')->assertJsonPath('data.0.name', 'New Name');
    }

    public function test_checkout_stock_change_is_visible_on_the_listing(): void
    {
        $product = Product::factory()->priced('12.00')->withStock(5)->create();
        $customer = User::factory()->create();
        CartItem::factory()->for(Cart::factory()->for($customer))->for($product)->create(['quantity' => 2]);

        $this->getJson('/api/products')->assertJsonPath('data.0.stock_quantity', 5);

        Sanctum::actingAs($customer);
        $this->postJson('/api/checkout')->assertCreated();

        $this->getJson('/api/products')->assertJsonPath('data.0.stock_quantity', 3);
    }
}
