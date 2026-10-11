<?php

namespace Tests\Feature\Catalog;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_valid_active_product_with_category(): void
    {
        $product = Product::factory()->create();

        $this->assertSame(ProductStatus::Active, $product->status);
        $this->assertInstanceOf(Category::class, $product->category);
        $this->assertGreaterThan(0, $product->stock_quantity);
        $this->assertTrue($product->isAvailable());
    }

    public function test_sku_is_normalized_and_unique(): void
    {
        $product = Product::factory()->create(['sku' => '  abc-123 ']);

        $this->assertSame('ABC-123', $product->fresh()->sku);

        $this->expectException(QueryException::class);
        Product::factory()->create(['sku' => 'abc-123']);
    }

    public function test_price_is_stored_with_two_decimals(): void
    {
        $product = Product::factory()->priced(19.5)->create();

        $this->assertSame('19.50', $product->fresh()->price);
    }

    public function test_status_is_cast_to_enum_and_defaults_to_active(): void
    {
        $product = Product::create([
            'name' => 'Plain Product',
            'sku' => 'PLAIN-1',
            'price' => '10.00',
        ]);

        $fresh = $product->fresh();
        $this->assertSame(ProductStatus::Active, $fresh->status);
        $this->assertSame(0, $fresh->stock_quantity);
    }

    public function test_available_scope_excludes_inactive_and_out_of_stock_products(): void
    {
        $available = Product::factory()->create();
        Product::factory()->inactive()->create();
        Product::factory()->outOfStock()->create();

        $this->assertSame([$available->id], Product::query()->available()->pluck('id')->all());
        $this->assertSame(2, Product::query()->active()->count());
        $this->assertSame(1, Product::query()->outOfStock()->count());
    }

    public function test_search_scope_is_case_insensitive_and_escapes_wildcards(): void
    {
        $phone = Product::factory()->create(['name' => 'Galaxy Phone']);
        $sale = Product::factory()->create(['name' => 'Mega 50% Sale Pack']);
        Product::factory()->create(['name' => 'Mega 500 Sale Pack']);

        $this->assertSame([$phone->id], Product::query()->search('galaxy')->pluck('id')->all());
        $this->assertSame([$sale->id], Product::query()->search('50%')->pluck('id')->all());
        $this->assertSame(3, Product::query()->search('   ')->count());
    }

    public function test_price_between_scope_is_inclusive_and_accepts_open_ranges(): void
    {
        Product::factory()->priced(10)->create();
        Product::factory()->priced(50)->create();
        Product::factory()->priced(100)->create();

        $this->assertSame(2, Product::query()->priceBetween(10, 50)->count());
        $this->assertSame(2, Product::query()->priceBetween(50, null)->count());
        $this->assertSame(1, Product::query()->priceBetween(null, 10)->count());
        $this->assertSame(3, Product::query()->priceBetween(null, null)->count());
    }

    public function test_in_category_scope_includes_subcategories(): void
    {
        $electronics = Category::factory()->create();
        $phones = Category::factory()->childOf($electronics)->create();
        $books = Category::factory()->create();

        $tv = Product::factory()->for($electronics)->create();
        $phone = Product::factory()->for($phones)->create();
        Product::factory()->for($books)->create();

        $this->assertEqualsCanonicalizing(
            [$tv->id, $phone->id],
            Product::query()->inCategory($electronics)->pluck('id')->all(),
        );
        $this->assertSame([$phone->id], Product::query()->inCategory($phones)->pluck('id')->all());
    }

    public function test_has_stock_for_checks_quantity_and_status(): void
    {
        $product = Product::factory()->withStock(5)->create();

        $this->assertTrue($product->hasStockFor(5));
        $this->assertFalse($product->hasStockFor(6));

        $product->status = ProductStatus::Inactive;
        $this->assertFalse($product->hasStockFor(1));
    }

    public function test_soft_deleted_products_are_hidden_but_sku_stays_reserved(): void
    {
        $product = Product::factory()->create(['sku' => 'GONE-1']);
        $product->delete();

        $this->assertNull(Product::find($product->id));
        $this->assertNotNull(Product::withTrashed()->find($product->id));

        $this->expectException(QueryException::class);
        Product::factory()->create(['sku' => 'GONE-1']);
    }

    public function test_deleting_a_category_keeps_its_products(): void
    {
        $category = Category::factory()->create();
        $product = Product::factory()->for($category)->create();

        $category->delete();

        $this->assertNull($product->fresh()->category_id);
    }
}
