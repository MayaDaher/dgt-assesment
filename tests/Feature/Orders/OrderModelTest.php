<?php

namespace Tests\Feature\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_factory_creates_a_pending_order_with_a_number(): void
    {
        $order = Order::factory()->create();

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertMatchesRegularExpression('/^ORD-\d{8}-[A-Z0-9]{8}$/', $order->order_number);
        $this->assertTrue($order->canBeCancelled());
    }

    public function test_order_number_is_generated_when_missing(): void
    {
        $order = Order::query()->create([
            'user_id' => User::factory()->create()->id,
            'subtotal' => '10.00',
            'discount_amount' => '0.00',
            'total' => '10.00',
        ]);

        $this->assertNotEmpty($order->order_number);
    }

    public function test_order_items_keep_a_snapshot_after_the_product_changes(): void
    {
        $product = Product::factory()->priced('19.99')->create(['name' => 'Old Name', 'sku' => 'OLD-1']);
        $item = OrderItem::factory()->for($product)->create([
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'unit_price' => '19.99',
            'quantity' => 2,
            'line_total' => '39.98',
        ]);

        $product->update(['name' => 'New Name', 'sku' => 'NEW-1', 'price' => '99.00']);

        $fresh = $item->fresh();
        $this->assertSame('Old Name', $fresh->product_name);
        $this->assertSame('OLD-1', $fresh->product_sku);
        $this->assertSame('19.99', $fresh->unit_price);
        $this->assertSame('39.98', $fresh->line_total);
    }

    public function test_deleting_a_product_keeps_the_order_item_snapshot(): void
    {
        $product = Product::factory()->create();
        $item = OrderItem::factory()->for($product)->create([
            'product_name' => $product->name,
            'product_sku' => $product->sku,
            'unit_price' => '10.00',
            'quantity' => 1,
            'line_total' => '10.00',
        ]);

        $product->forceDelete();

        $fresh = $item->fresh();
        $this->assertNull($fresh->product_id);
        $this->assertSame($product->name, $fresh->product_name);
        $this->assertSame($product->sku, $fresh->product_sku);
    }

    public function test_a_user_with_orders_cannot_be_hard_deleted(): void
    {
        $order = Order::factory()->create();

        $this->expectException(QueryException::class);
        $order->user->delete();
    }

    public function test_idempotency_key_is_unique_per_customer(): void
    {
        $user = User::factory()->create();
        Order::factory()->for($user)->create(['idempotency_key' => 'same-key-12']);

        $this->expectException(QueryException::class);
        Order::factory()->for($user)->create(['idempotency_key' => 'same-key-12']);
    }
}
