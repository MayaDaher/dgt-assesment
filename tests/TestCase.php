<?php

namespace Tests;

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
        return $this->json(
            'POST',
            '/api/checkout',
            array_replace_recursive($this->shippingPayload(), $payload),
            $headers,
        );
    }
}
