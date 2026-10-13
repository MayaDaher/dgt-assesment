<?php

namespace Database\Factories;

use App\Enums\ProductAuditSource;
use App\Enums\PromotionAuditField;
use App\Models\Promotion;
use App\Models\PromotionAudit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionAudit>
 */
class PromotionAuditFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'promotion_id' => Promotion::factory(),
            'promotion_code' => 'AUDIT10',
            'user_id' => User::factory()->admin(),
            'field' => PromotionAuditField::Value,
            'old_value' => '10.00',
            'new_value' => '15.00',
            'source' => ProductAuditSource::Admin,
            'created_at' => now(),
        ];
    }
}
