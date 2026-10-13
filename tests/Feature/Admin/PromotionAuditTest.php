<?php

namespace Tests\Feature\Admin;

use App\Enums\ProductAuditSource;
use App\Enums\PromotionAuditField;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PromotionAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_guests_and_customers_cannot_read_promotion_audits(): void
    {
        $promotion = Promotion::factory()->create();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/admin/promotions/{$promotion->id}/audits")->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/admin/promotions/{$promotion->id}/audits")->assertForbidden();
    }

    public function test_admin_value_and_status_edits_are_recorded(): void
    {
        $promotion = Promotion::factory()->fixed(10)->create(['code' => 'SAVE10', 'is_active' => true]);

        $this->patchJson("/api/admin/promotions/{$promotion->id}", [
            'value' => '12.50',
            'is_active' => false,
        ])->assertOk();

        $this->getJson("/api/admin/promotions/{$promotion->id}/audits")
            ->assertOk()
            ->assertJsonPath('data.0.field', PromotionAuditField::IsActive->value)
            ->assertJsonPath('data.0.old_value', '1')
            ->assertJsonPath('data.0.new_value', '0')
            ->assertJsonPath('data.0.source', ProductAuditSource::Admin->value)
            ->assertJsonPath('data.1.field', PromotionAuditField::Value->value)
            ->assertJsonPath('data.1.old_value', '10.00')
            ->assertJsonPath('data.1.new_value', '12.50');
    }

    public function test_creating_a_promotion_is_not_an_edit(): void
    {
        $this->postJson('/api/admin/promotions', [
            'code' => 'NEW10',
            'type' => 'fixed',
            'value' => '5',
        ])->assertCreated();

        $this->assertDatabaseCount('promotion_audits', 0);
    }

    public function test_description_only_edits_do_not_write_audits(): void
    {
        $promotion = Promotion::factory()->create();

        $this->patchJson("/api/admin/promotions/{$promotion->id}", [
            'description' => 'Just a note',
        ])->assertOk();

        $this->assertDatabaseCount('promotion_audits', 0);
    }

    public function test_checkout_usage_is_not_a_promotion_audit(): void
    {
        $customer = User::factory()->create();
        $promotion = Promotion::factory()->fixed(5)->create(['code' => 'FIVE']);
        $cart = Cart::query()->firstOrCreate(['user_id' => $customer->id]);
        CartItem::factory()
            ->for($cart)
            ->for(Product::factory()->priced('20.00')->withStock(5))
            ->create(['quantity' => 1]);
        $cart->update(['promotion_id' => $promotion->id]);

        Sanctum::actingAs($customer);
        $this->checkout()->assertCreated();

        $this->assertSame(1, $promotion->fresh()->used_count);
        $this->assertDatabaseCount('promotion_audits', 0);
    }

    public function test_promotion_audits_cannot_be_updated_or_deleted(): void
    {
        $audit = PromotionAudit::factory()->create();

        $this->expectException(\LogicException::class);
        $audit->update(['new_value' => 'hacked']);
    }

    public function test_global_promotion_audit_log_can_filter_by_field(): void
    {
        $promotion = Promotion::factory()->fixed(10)->create();
        $this->patchJson("/api/admin/promotions/{$promotion->id}", ['value' => '11.00'])->assertOk();
        $this->patchJson("/api/admin/promotions/{$promotion->id}", ['is_active' => false])->assertOk();

        $this->getJson('/api/admin/promotion-audits?field=value')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.field', 'value');
    }
}
