<?php

namespace App\Http\Requests\Checkout;

use App\Services\Checkout\ShippingAddress;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Checkout does not trust client money fields as the charge (`price`, `subtotal`, `total`
 * are ignored). `expected_total` is a confirmation of the last displayed cart total, not
 * the amount to bill. Shipping is snapshotted onto the order; later profile edits cannot
 * rewrite it. Clients may send `Idempotency-Key` so a retried request cannot create a
 * second order.
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
            'expected_total' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
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

    public function expectedTotal(): string
    {
        return (string) $this->validated('expected_total');
    }

    public function shipping(): ShippingAddress
    {
        return ShippingAddress::fromArray($this->validated('shipping'));
    }
}
