<?php

namespace App\Http\Requests\Cart;

use App\Models\CartItem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddCartItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only the product and quantity are accepted; any price sent by the client is ignored.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['sometimes', 'integer', 'min:1', 'max:'.CartItem::MAX_QUANTITY],
        ];
    }

    public function productId(): int
    {
        return (int) $this->validated('product_id');
    }

    public function quantity(): int
    {
        return (int) ($this->validated('quantity') ?? 1);
    }
}
