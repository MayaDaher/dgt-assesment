<?php

namespace App\Http\Requests\Cart;

use App\Models\CartItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * To remove a line, use DELETE /api/cart/items/{id} instead of quantity 0.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'quantity' => ['required', 'integer', 'min:1', 'max:'.CartItem::MAX_QUANTITY],
        ];
    }

    public function quantity(): int
    {
        return (int) $this->validated('quantity');
    }
}
