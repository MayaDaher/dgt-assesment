<?php

namespace Database\Factories;

use App\Enums\ProductAuditField;
use App\Enums\ProductAuditSource;
use App\Models\Product;
use App\Models\ProductAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductAudit>
 */
class ProductAuditFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product = Product::factory();

        return [
            'product_id' => $product,
            'product_sku' => 'SKU-AUDIT',
            'product_name' => 'Audited Product',
            'user_id' => User::factory()->admin(),
            'field' => ProductAuditField::Price,
            'old_value' => '10.00',
            'new_value' => '12.00',
            'source' => ProductAuditSource::Admin,
            'created_at' => now(),
        ];
    }

    public function stock(): static
    {
        return $this->state(fn () => [
            'field' => ProductAuditField::StockQuantity,
            'old_value' => '5',
            'new_value' => '8',
        ]);
    }
}
