<?php

namespace App\Http\Requests\Checkout;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Checkout accepts no trusted money fields. Any price or total in the body is ignored.
 * Clients may send `Idempotency-Key` so a retried request cannot create a second order.
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
}
