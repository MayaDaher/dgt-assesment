<?php

namespace Database\Factories;

use App\Enums\PromotionType;
use App\Models\Promotion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Promotion>
 */
class PromotionFactory extends Factory
{
    /**
     * Default: an always-valid 10% promotion with no limits.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'PROMO'.Str::upper(Str::random(8)),
            'description' => fake()->sentence(),
            'type' => PromotionType::Percentage,
            'value' => '10.00',
            'min_cart_amount' => null,
            'max_discount_amount' => null,
            'starts_at' => null,
            'ends_at' => null,
            'usage_limit' => null,
            'per_customer_limit' => null,
            'is_active' => true,
        ];
    }

    public function percentage(float|string $value, float|string|null $maxDiscount = null): static
    {
        return $this->state(fn () => [
            'type' => PromotionType::Percentage,
            'value' => $value,
            'max_discount_amount' => $maxDiscount,
        ]);
    }

    public function fixed(float|string $value): static
    {
        return $this->state(fn () => [
            'type' => PromotionType::Fixed,
            'value' => $value,
        ]);
    }

    public function minCart(float|string $amount): static
    {
        return $this->state(fn () => ['min_cart_amount' => $amount]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function notStarted(): static
    {
        return $this->state(fn () => [
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addMonth(),
        ]);
    }

    public function usageLimit(int $limit, int $used = 0): static
    {
        return $this->state(fn () => ['usage_limit' => $limit])
            ->afterCreating(fn (Promotion $promotion) => $promotion->forceFill(['used_count' => $used])->save());
    }

    public function perCustomerLimit(int $limit): static
    {
        return $this->state(fn () => ['per_customer_limit' => $limit]);
    }
}
