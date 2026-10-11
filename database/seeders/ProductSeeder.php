<?php

namespace Database\Seeders;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    private const DEMO_PRODUCT_COUNT = 40;

    /**
     * Hand-written catalog with predictable values for manual testing and the README examples.
     * SNK-LTD-001 has stock 5 to reproduce the concurrency scenario (buyer A wants 4, buyer B wants 3).
     *
     * @var list<array{category: string, name: string, sku: string, price: string, stock: int, status?: ProductStatus, description: string}>
     */
    private const CATALOG = [
        ['category' => 'electronics-phones', 'name' => 'iPhone 15 Pro', 'sku' => 'PHN-APL-15P', 'price' => '999.00', 'stock' => 25, 'description' => '6.1-inch display, A17 Pro chip, 256GB storage.'],
        ['category' => 'electronics-phones', 'name' => 'Samsung Galaxy S24', 'sku' => 'PHN-SMS-S24', 'price' => '849.99', 'stock' => 30, 'description' => '6.2-inch AMOLED display, 128GB storage.'],
        ['category' => 'electronics-phones', 'name' => 'Google Pixel 8', 'sku' => 'PHN-GGL-PX8', 'price' => '699.00', 'stock' => 0, 'description' => 'Tensor G3 chip, best-in-class camera.'],
        ['category' => 'electronics-laptops', 'name' => 'MacBook Air 13 M3', 'sku' => 'LAP-APL-MBA13', 'price' => '1299.00', 'stock' => 12, 'description' => 'M3 chip, 16GB RAM, 512GB SSD.'],
        ['category' => 'electronics-laptops', 'name' => 'Dell XPS 15', 'sku' => 'LAP-DEL-XPS15', 'price' => '1849.50', 'stock' => 8, 'description' => 'Intel Core i7, 32GB RAM, OLED display.'],
        ['category' => 'electronics-audio', 'name' => 'Sony WH-1000XM5 Headphones', 'sku' => 'AUD-SNY-XM5', 'price' => '399.99', 'stock' => 40, 'description' => 'Industry-leading noise cancelling headphones.'],
        ['category' => 'electronics-audio', 'name' => 'AirPods Pro 2', 'sku' => 'AUD-APL-APP2', 'price' => '249.00', 'stock' => 60, 'description' => 'Active noise cancellation with USB-C case.'],
        ['category' => 'fashion-shoes', 'name' => 'Limited Edition Sneakers', 'sku' => 'SNK-LTD-001', 'price' => '120.00', 'stock' => 5, 'description' => 'Only a handful available. Used for the concurrency demo.'],
        ['category' => 'fashion-shoes', 'name' => 'Classic Leather Boots', 'sku' => 'SHO-BOT-CLS', 'price' => '189.90', 'stock' => 15, 'description' => 'Handmade full-grain leather boots.'],
        ['category' => 'fashion-men', 'name' => 'Slim Fit Denim Jacket', 'sku' => 'FSH-MEN-DNJ', 'price' => '79.50', 'stock' => 35, 'description' => 'Washed blue denim, slim fit.'],
        ['category' => 'fashion-women', 'name' => 'Silk Summer Dress', 'sku' => 'FSH-WMN-SSD', 'price' => '129.00', 'stock' => 20, 'description' => '100% silk, floral print.'],
        ['category' => 'home-kitchen-appliances', 'name' => 'Espresso Machine', 'sku' => 'HOM-APP-ESP', 'price' => '549.00', 'stock' => 10, 'description' => '15-bar pump with milk frother.'],
        ['category' => 'home-kitchen-furniture', 'name' => 'Ergonomic Office Chair', 'sku' => 'HOM-FUR-CHR', 'price' => '329.00', 'stock' => 18, 'description' => 'Adjustable lumbar support and armrests.'],
        ['category' => 'books', 'name' => 'Clean Architecture', 'sku' => 'BOK-CLN-ARC', 'price' => '34.99', 'stock' => 100, 'description' => 'A Craftsman\'s Guide to Software Structure and Design.'],
        ['category' => 'books', 'name' => 'Designing Data-Intensive Applications', 'sku' => 'BOK-DDI-APP', 'price' => '45.50', 'stock' => 80, 'description' => 'The big ideas behind reliable, scalable systems.'],
        ['category' => 'sports-fitness', 'name' => 'Adjustable Dumbbell Set', 'sku' => 'SPT-FIT-DMB', 'price' => '299.00', 'stock' => 14, 'description' => 'Adjustable from 2.5kg to 24kg.'],
        ['category' => 'sports-outdoor', 'name' => 'Camping Tent 4-Person', 'sku' => 'SPT-OUT-TNT', 'price' => '159.00', 'stock' => 9, 'description' => 'Waterproof, sets up in 5 minutes.'],
        ['category' => 'sports-outdoor', 'name' => 'Discontinued Hiking Poles', 'sku' => 'SPT-OUT-HKP', 'price' => '49.00', 'stock' => 22, 'status' => ProductStatus::Inactive, 'description' => 'No longer sold; hidden from the storefront.'],
    ];

    public function run(): void
    {
        $categories = Category::query()->pluck('id', 'slug');

        foreach (self::CATALOG as $item) {
            Product::withTrashed()->updateOrCreate(
                ['sku' => $item['sku']],
                [
                    'category_id' => $categories[$item['category']] ?? null,
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'price' => $item['price'],
                    'stock_quantity' => $item['stock'],
                    'status' => $item['status'] ?? ProductStatus::Active,
                    'deleted_at' => null,
                ],
            );
        }

        $this->seedDemoProducts();
    }

    /**
     * Bulk products for exercising pagination, filters and sorting.
     * SKUs are deterministic so re-running the seeder does not create duplicates.
     */
    private function seedDemoProducts(): void
    {
        $leafCategoryIds = Category::query()->doesntHave('children')->pluck('id');

        for ($i = 1; $i <= self::DEMO_PRODUCT_COUNT; $i++) {
            $sku = sprintf('DEMO-%04d', $i);

            if (Product::withTrashed()->where('sku', $sku)->exists()) {
                continue;
            }

            $factory = Product::factory()->state([
                'sku' => $sku,
                'category_id' => $leafCategoryIds->random(),
            ]);

            if ($i % 8 === 0) {
                $factory = $factory->inactive();
            } elseif ($i % 6 === 0) {
                $factory = $factory->outOfStock();
            }

            $factory->create();
        }
    }
}
