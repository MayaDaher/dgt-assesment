<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_number' => 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(8)),
            'user_id' => User::factory(),
            'promotion_id' => null,
            'promotion_code' => null,
            'status' => OrderStatus::Pending,
            'subtotal' => '100.00',
            'discount_amount' => '0.00',
            'total' => '100.00',
            'idempotency_key' => null,
            'idempotency_scope' => null,
            'shipping_name' => 'Maya Daher',
            'shipping_phone' => '+961 70 123 456',
            'shipping_address_line1' => 'Hamra St., Building 12, Floor 3',
            'shipping_address_line2' => 'Apartment 5',
            'shipping_city' => 'Beirut',
            'shipping_state' => 'Beirut Governorate',
            'shipping_postal_code' => '1103',
            'shipping_country' => 'Lebanon',
            'cancelled_at' => null,
            'paid_at' => null,
            'processing_at' => null,
            'shipped_at' => null,
            'delivered_at' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Processing,
            'paid_at' => now()->subMinute(),
            'processing_at' => now(),
        ]);
    }

    public function shipped(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Shipped,
            'paid_at' => now()->subMinutes(2),
            'processing_at' => now()->subMinute(),
            'shipped_at' => now(),
        ]);
    }

    public function delivered(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Delivered,
            'paid_at' => now()->subMinutes(3),
            'processing_at' => now()->subMinutes(2),
            'shipped_at' => now()->subMinute(),
            'delivered_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
