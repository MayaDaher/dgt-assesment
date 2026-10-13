<?php

namespace Tests\Feature\Admin;

use App\Enums\ProductAuditField;
use App\Enums\ProductAuditSource;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\ProductAudit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class ProductAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        Sanctum::actingAs($this->admin);
    }

    public function test_guests_and_customers_cannot_read_audits(): void
    {
        $product = Product::factory()->create();

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/admin/products/{$product->id}/audits")->assertUnauthorized();
        $this->getJson('/api/admin/audits')->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/admin/products/{$product->id}/audits")
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
        $this->getJson('/api/admin/audits')->assertForbidden();
    }

    public function test_admin_price_and_stock_edits_are_recorded_with_the_actor(): void
    {
        $product = Product::factory()->create([
            'name' => 'Gadget',
            'sku' => 'GAD-1',
            'price' => '19.99',
            'stock_quantity' => 4,
        ]);

        $this->patchJson("/api/admin/products/{$product->id}", [
            'price' => '24.50',
            'stock_quantity' => 7,
        ])->assertOk();

        $this->assertDatabaseCount('product_audits', 2);

        $this->getJson("/api/admin/products/{$product->id}/audits")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.field', ProductAuditField::StockQuantity->value)
            ->assertJsonPath('data.0.old_value', '4')
            ->assertJsonPath('data.0.new_value', '7')
            ->assertJsonPath('data.0.source', ProductAuditSource::Admin->value)
            ->assertJsonPath('data.0.product_sku', 'GAD-1')
            ->assertJsonPath('data.0.actor.id', $this->admin->id)
            ->assertJsonPath('data.0.actor.email', $this->admin->email)
            ->assertJsonPath('data.1.field', ProductAuditField::Price->value)
            ->assertJsonPath('data.1.old_value', '19.99')
            ->assertJsonPath('data.1.new_value', '24.50');
    }

    public function test_unchanged_price_or_name_only_edits_do_not_write_audits(): void
    {
        $product = Product::factory()->create(['price' => '12.50', 'stock_quantity' => 3]);

        $this->patchJson("/api/admin/products/{$product->id}", ['name' => 'Renamed'])
            ->assertOk();
        $this->assertDatabaseCount('product_audits', 0);

        $this->patchJson("/api/admin/products/{$product->id}", ['price' => '12.50'])
            ->assertOk();
        $this->assertDatabaseCount('product_audits', 0);
    }

    public function test_creating_a_product_is_not_an_edit(): void
    {
        $this->postJson('/api/admin/products', [
            'name' => 'New',
            'sku' => 'NEW-1',
            'price' => '5.00',
            'stock_quantity' => 1,
        ])->assertCreated();

        $this->assertDatabaseCount('product_audits', 0);
    }

    public function test_checkout_and_cancel_stock_movements_are_not_catalog_edits(): void
    {
        $customer = User::factory()->create();
        $product = Product::factory()->create(['price' => '10.00', 'stock_quantity' => 5]);
        $cart = Cart::query()->firstOrCreate(['user_id' => $customer->id]);
        CartItem::factory()->for($cart)->for($product)->create(['quantity' => 2]);

        Sanctum::actingAs($customer);
        $orderId = $this->checkout()->assertCreated()->json('data.id');
        $this->assertDatabaseCount('product_audits', 0);
        $this->assertSame(3, $product->fresh()->stock_quantity);

        $this->postJson("/api/orders/{$orderId}/cancel")->assertOk();
        $this->assertDatabaseCount('product_audits', 0);
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_unauthenticated_model_updates_are_logged_as_system(): void
    {
        $product = Product::factory()->create(['price' => '10.00']);
        $this->app['auth']->forgetGuards();

        $product->update(['price' => '11.00']);

        $this->assertDatabaseHas('product_audits', [
            'product_id' => $product->id,
            'field' => ProductAuditField::Price->value,
            'old_value' => '10.00',
            'new_value' => '11.00',
            'source' => ProductAuditSource::System->value,
            'user_id' => null,
        ]);
    }

    public function test_admin_can_filter_the_global_audit_log(): void
    {
        $first = Product::factory()->create(['price' => '10.00', 'stock_quantity' => 1]);
        $second = Product::factory()->create(['price' => '20.00', 'stock_quantity' => 2]);

        $this->patchJson("/api/admin/products/{$first->id}", ['price' => '15.00'])->assertOk();
        $this->patchJson("/api/admin/products/{$second->id}", ['stock_quantity' => 9])->assertOk();

        $this->getJson('/api/admin/audits')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/admin/audits?field=price')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_id', $first->id)
            ->assertJsonPath('data.0.field', 'price');

        $this->getJson('/api/admin/audits?product_id='.$second->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.field', 'stock_quantity');

        $this->getJson('/api/admin/audits?user_id='.$this->admin->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/admin/audits?field=banana')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['field']);
    }

    public function test_audits_of_a_soft_deleted_product_remain_readable(): void
    {
        $product = Product::factory()->create(['price' => '8.00']);

        $this->patchJson("/api/admin/products/{$product->id}", ['price' => '9.00'])->assertOk();
        $this->deleteJson("/api/admin/products/{$product->id}")->assertOk();

        $this->getJson("/api/admin/products/{$product->id}/audits")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.old_value', '8.00')
            ->assertJsonPath('data.0.new_value', '9.00');
    }

    public function test_product_audits_cannot_be_updated_or_deleted(): void
    {
        $audit = ProductAudit::factory()->create();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Product audits are append-only.');

        $audit->update(['new_value' => '999.00']);
    }

    public function test_product_audits_cannot_be_deleted(): void
    {
        $audit = ProductAudit::factory()->create();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Product audits are append-only.');

        $audit->delete();
    }

    public function test_missing_product_audits_return_404(): void
    {
        $this->getJson('/api/admin/products/999999/audits')
            ->assertNotFound();
    }
}
