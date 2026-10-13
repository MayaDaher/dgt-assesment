<?php

namespace App\Http\Requests\Checkout;

use App\Services\Checkout\ShippingAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Checkout accepts no trusted money fields. Any price or total in the body is ignored.
 * Clients may send `Idempotency-Key` so a retried request cannot create a second order.
 * Shipping is snapshotted onto the order; later profile changes cannot rewrite it.
 */
class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'min:8', 'max:100'],
            'shipping' => ['required', 'array'],
            'shipping.name' => ['required', 'string', 'max:100'],
            'shipping.phone' => ['required', 'string', 'max:30'],
            'shipping.address_line1' => ['required', 'string', 'max:255'],
            'shipping.address_line2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'shipping.city' => ['required', 'string', 'max:100'],
            'shipping.state' => ['required', 'string', 'max:100'],
            'shipping.postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'shipping.country' => ['required', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $key = $this->header('Idempotency-Key');

        if (is_string($key) && trim($key) !== '') {
            $this->merge(['idempotency_key' => trim($key)]);
        }
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->validated('idempotency_key') ?? null;

        return filled($key) ? (string) $key : null;
    }

    public function shipping(): ShippingAddress
    {
        return ShippingAddress::fromArray($this->validated('shipping'));
    }
}
