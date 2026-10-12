<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Enums\PromotionType;
use App\Models\Promotion;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesPromotionPayload
{
    protected function prepareForValidation(): void
    {
        if ($this->exists('code') && is_string($this->input('code'))) {
            $this->merge(['code' => Promotion::normalizeCode($this->input('code'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function promotionRules(?Promotion $existing = null): array
    {
        $required = $existing === null ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:50', Rule::unique('promotions', 'code')->ignore($existing)],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => [$required, Rule::enum(PromotionType::class)],
            'value' => [$required, 'numeric', 'gt:0'],
            'min_cart_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'max_discount_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    protected function promotionAfter(?Promotion $existing = null): array
    {
        return [
            function (Validator $validator) use ($existing) {
                $type = $this->input('type', $existing?->type->value);
                $value = $this->input('value', $existing?->value);

                if ($type === PromotionType::Percentage->value && is_numeric($value) && (float) $value > 100) {
                    $validator->errors()->add('value', 'A percentage value may not be greater than 100.');
                }

                $limit = $this->input('usage_limit');

                if ($existing !== null && is_numeric($limit) && (int) $limit < $existing->used_count) {
                    $validator->errors()->add('usage_limit', 'The usage limit cannot be lower than the number of times this code has already been used.');
                }
            },
        ];
    }
}
