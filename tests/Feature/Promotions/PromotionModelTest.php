<?php

namespace Tests\Feature\Promotions;

use App\Enums\PromotionScope;
use App\Enums\PromotionType;
use App\Models\Cart;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PromotionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_code_is_normalized_to_uppercase_and_unique(): void
    {
        $promotion = Promotion::factory()->create(['code' => '  summer20 ']);

        $this->assertSame('SUMMER20', $promotion->fresh()->code);

        $this->expectException(QueryException::class);
        Promotion::factory()->create(['code' => 'Summer20']);
    }

    public function test_casts_and_defaults(): void
    {
        $promotion = Promotion::factory()->percentage(20, 50)->minCart(100)->create()->fresh();

        $this->assertSame(PromotionType::Percentage, $promotion->type);
        $this->assertSame(PromotionScope::Order, $promotion->scope);
        $this->assertSame('20.00', $promotion->value);
        $this->assertSame('50.00', $promotion->max_discount_amount);
        $this->assertSame('100.00', $promotion->min_cart_amount);
        $this->assertSame(0, $promotion->used_count);
        $this->assertTrue($promotion->is_active);
    }

    public function test_used_count_cannot_be_mass_assigned(): void
    {
        $promotion = Promotion::factory()->create();
        $promotion->update(['used_count' => 999]);

        $this->assertSame(0, $promotion->fresh()->used_count);
    }

    public function test_date_window_helpers(): void
    {
        $this->assertTrue(Promotion::factory()->make()->hasStarted());
        $this->assertFalse(Promotion::factory()->make()->hasEnded());
        $this->assertFalse(Promotion::factory()->notStarted()->make()->hasStarted());
        $this->assertTrue(Promotion::factory()->expired()->make()->hasEnded());
    }

    public function test_usage_limit_helpers(): void
    {
        $user = User::factory()->create();
        $exhausted = Promotion::factory()->usageLimit(2, used: 2)->create();
        $available = Promotion::factory()->usageLimit(2, used: 1)->create();

        $this->assertTrue($exhausted->fresh()->hasReachedUsageLimit());
        $this->assertFalse($available->fresh()->hasReachedUsageLimit());

        PromotionUsage::create(['promotion_id' => $available->id, 'user_id' => $user->id]);
        $this->assertSame(1, $available->usageCountFor($user));
    }

    public function test_cart_keeps_reference_to_soft_deleted_promotion(): void
    {
        $promotion = Promotion::factory()->create();
        $cart = Cart::factory()->create(['promotion_id' => $promotion->id]);

        $promotion->delete();

        $this->assertTrue($cart->fresh()->promotion->trashed());
    }

    public function test_seeder_creates_one_promotion_per_rule_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(11, Promotion::count());

        $audio = Promotion::query()->where('code', 'AUDIO10')->sole();
        $this->assertSame(PromotionScope::Categories, $audio->scope);
        $this->assertTrue($audio->categories()->where('slug', 'electronics-audio')->exists());

        $sneakers = Promotion::query()->where('code', 'SNKDEAL')->sole();
        $this->assertSame(PromotionScope::Products, $sneakers->scope);
        $this->assertTrue($sneakers->products()->where('sku', 'SNK-LTD-001')->exists());

        $summer = Promotion::query()->where('code', 'SUMMER20')->sole();
        $this->assertSame(PromotionType::Percentage, $summer->type);
        $this->assertSame('20.00', $summer->value);
        $this->assertSame('100.00', $summer->min_cart_amount);
        $this->assertSame('50.00', $summer->max_discount_amount);

        $this->assertTrue(Promotion::query()->where('code', 'SOLDOUT')->sole()->hasReachedUsageLimit());
        $this->assertSame(1, PromotionUsage::count());
    }
}
