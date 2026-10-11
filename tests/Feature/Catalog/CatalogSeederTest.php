<?php

namespace Tests\Feature\Catalog;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CategorySeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_build_the_catalog(): void
    {
        $this->seed(DatabaseSeeder::class);

        $expectedCategories = count(CategorySeeder::TREE) + count(array_merge(...array_values(CategorySeeder::TREE)));
        $this->assertSame($expectedCategories, Category::count());

        $phones = Category::query()->where('slug', 'electronics-phones')->sole();
        $this->assertSame('electronics', $phones->parent->slug);

        $this->assertSame(58, Product::count());
        $this->assertGreaterThan(0, Product::query()->where('status', ProductStatus::Inactive)->count());
        $this->assertGreaterThan(0, Product::query()->outOfStock()->count());
        $this->assertSame(0, Product::query()->whereNull('category_id')->count());

        $sneakers = Product::query()->where('sku', 'SNK-LTD-001')->sole();
        $this->assertSame(5, $sneakers->stock_quantity);
        $this->assertSame('120.00', $sneakers->price);
    }

    public function test_seeders_are_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $counts = [User::count(), Category::count(), Product::count()];

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($counts, [User::count(), Category::count(), Product::count()]);
    }
}
