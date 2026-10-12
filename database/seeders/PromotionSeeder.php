<?php

namespace Database\Seeders;

use App\Enums\PromotionScope;
use App\Enums\PromotionType;
use App\Models\Category;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One promotion per eligibility rule so every case can be tried by hand.
 * Dates are relative to the moment the seeder runs.
 */
class PromotionSeeder extends Seeder
{
    public function run(): void
    {
        $promotions = [
            // The example from the assessment brief.
            'SUMMER20' => [
                'description' => '20% off orders of $100 or more, up to $50.',
                'type' => PromotionType::Percentage, 'value' => '20.00',
                'min_cart_amount' => '100.00', 'max_discount_amount' => '50.00',
                'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonths(3),
                'usage_limit' => 1000, 'per_customer_limit' => 3,
            ],
            'WELCOME10' => [
                'description' => '$10 off your first order over $50.',
                'type' => PromotionType::Fixed, 'value' => '10.00',
                'min_cart_amount' => '50.00', 'per_customer_limit' => 1,
            ],
            'BIGSPENDER' => [
                'description' => '$100 off orders of $1,000 or more.',
                'type' => PromotionType::Fixed, 'value' => '100.00',
                'min_cart_amount' => '1000.00',
            ],
            'HALFPRICE' => [
                'description' => '50% off, no cap. Only 3 uses in total.',
                'type' => PromotionType::Percentage, 'value' => '50.00',
                'usage_limit' => 3,
            ],
            'SOLDOUT' => [
                'description' => 'Global usage limit already reached.',
                'type' => PromotionType::Percentage, 'value' => '30.00',
                'usage_limit' => 2, 'used_count' => 2,
            ],
            'ONCEONLY' => [
                'description' => 'Already used once by customer@example.com (per-customer limit 1).',
                'type' => PromotionType::Fixed, 'value' => '5.00',
                'per_customer_limit' => 1,
            ],
            'EXPIRED15' => [
                'description' => 'Ended yesterday.',
                'type' => PromotionType::Percentage, 'value' => '15.00',
                'starts_at' => now()->subMonth(), 'ends_at' => now()->subDay(),
            ],
            'FUTURE25' => [
                'description' => 'Starts next week.',
                'type' => PromotionType::Percentage, 'value' => '25.00',
                'starts_at' => now()->addWeek(), 'ends_at' => now()->addMonth(),
            ],
            'DISABLED5' => [
                'description' => 'Switched off by an admin.',
                'type' => PromotionType::Fixed, 'value' => '5.00',
                'is_active' => false,
            ],
            'AUDIO10' => [
                'description' => '10% off audio products (headphones, earbuds).',
                'type' => PromotionType::Percentage, 'value' => '10.00',
                'scope' => PromotionScope::Categories,
            ],
            'SNKDEAL' => [
                'description' => '$20 off Limited Edition Sneakers.',
                'type' => PromotionType::Fixed, 'value' => '20.00',
                'scope' => PromotionScope::Products,
            ],
        ];

        foreach ($promotions as $code => $attributes) {
            $usedCount = $attributes['used_count'] ?? 0;
            unset($attributes['used_count']);

            Promotion::withTrashed()->updateOrCreate(
                ['code' => $code],
                [
                    'min_cart_amount' => null,
                    'max_discount_amount' => null,
                    'starts_at' => null,
                    'ends_at' => null,
                    'usage_limit' => null,
                    'per_customer_limit' => null,
                    'is_active' => true,
                    'scope' => PromotionScope::Order,
                    ...$attributes,
                ],
            )->forceFill(['used_count' => $usedCount, 'deleted_at' => null])->save();
        }

        $this->seedPriorUsage('ONCEONLY', 'customer@example.com');
        $this->attachCategoryScope('AUDIO10', 'electronics-audio');
        $this->attachProductScope('SNKDEAL', 'SNK-LTD-001');
    }

    private function seedPriorUsage(string $code, string $email): void
    {
        $promotion = Promotion::query()->where('code', $code)->first();
        $user = User::query()->where('email', $email)->first();

        if ($promotion === null || $user === null) {
            return;
        }

        PromotionUsage::query()->firstOrCreate(['promotion_id' => $promotion->id, 'user_id' => $user->id]);
    }

    private function attachCategoryScope(string $code, string $categorySlug): void
    {
        $promotion = Promotion::query()->where('code', $code)->first();
        $category = Category::query()->where('slug', $categorySlug)->first();

        if ($promotion === null || $category === null) {
            return;
        }

        $promotion->syncRestrictions(productIds: [], categoryIds: [$category->id]);
    }

    private function attachProductScope(string $code, string $sku): void
    {
        $promotion = Promotion::query()->where('code', $code)->first();
        $product = Product::query()->where('sku', $sku)->first();

        if ($promotion === null || $product === null) {
            return;
        }

        $promotion->syncRestrictions(productIds: [$product->id], categoryIds: []);
    }
}
