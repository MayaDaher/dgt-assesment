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
            'cancelled_at' => null,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Paid]);
    }

    public function processing(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Processing]);
    }

    public function shipped(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Shipped]);
    }

    public function delivered(): static
    {
        return $this->state(fn () => ['status' => OrderStatus::Delivered]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => OrderStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }
}
