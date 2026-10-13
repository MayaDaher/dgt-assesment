<?php

namespace Tests;

use App\Models\Cart;
use App\Services\Cart\CartCalculator;
use App\Support\Money;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array{shipping: array<string, string>}
     */
    protected function shippingPayload(array $overrides = []): array
    {
        return [
            'shipping' => array_merge([
                'name' => 'Maya Daher',
                'phone' => '+961 70 123 456',
                'address_line1' => 'Hamra St., Building 12, Floor 3',
                'address_line2' => 'Apartment 5',
                'city' => 'Beirut',
                'state' => 'Beirut Governorate',
                'postal_code' => '1103',
                'country' => 'Lebanon',
            ], $overrides),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    protected function checkout(array $payload = [], array $headers = []): TestResponse
    {
        $body = array_replace_recursive($this->shippingPayload(), $payload);

        if (! array_key_exists('expected_total', $body)) {
            $body['expected_total'] = $this->displayedCartTotal();
        }

        return $this->json('POST', '/api/checkout', $body, $headers);
    }

    /**
     * Payable total a real client would copy from GET /cart (`data.total`).
     */
    protected function displayedCartTotal(): string
    {
        $user = $this->app['auth']->user();

        if ($user === null) {
            return '0.00';
        }

        $cart = Cart::query()->where('user_id', $user->id)->first();

        if ($cart === null) {
            return '0.00';
        }

        return Money::format(app(CartCalculator::class)->summarize($cart)->totalCents());
    }
}
