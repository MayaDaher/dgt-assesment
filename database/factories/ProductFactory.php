<?php

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'name' => Str::title(fake()->words(fake()->numberBetween(2, 4), true)),
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'description' => fake()->paragraph(),
            'price' => fake()->randomFloat(2, 5, 1000),
            'stock_quantity' => fake()->numberBetween(1, 100),
            'status' => ProductStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => ProductStatus::Inactive]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn () => ['stock_quantity' => 0]);
    }

    public function withStock(int $quantity): static
    {
        return $this->state(fn () => ['stock_quantity' => $quantity]);
    }

    public function priced(float|string $price): static
    {
        return $this->state(fn () => ['price' => $price]);
    }

    public function withoutCategory(): static
    {
        return $this->state(fn () => ['category_id' => null]);
    }
}
