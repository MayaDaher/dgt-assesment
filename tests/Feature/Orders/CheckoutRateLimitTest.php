<?php

namespace Tests\Feature\Orders;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CheckoutRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_checkout_is_limited_to_ten_requests_per_minute(): void
    {
        Sanctum::actingAs(User::factory()->create());

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/checkout')->assertUnprocessable();
        }

        $this->postJson('/api/checkout')
            ->assertTooManyRequests()
            ->assertJsonPath('code', 'TOO_MANY_REQUESTS')
            ->assertHeader('Retry-After');
    }

    public function test_order_listing_is_not_limited_by_the_checkout_budget(): void
    {
        Sanctum::actingAs(User::factory()->create());

        for ($i = 0; $i < 15; $i++) {
            $this->getJson('/api/orders')->assertOk();
        }
    }
}
