<?php

namespace App\Http\Requests\Admin;

use App\Enums\ProductAuditField;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ListProductAuditsRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['sometimes', 'nullable', 'integer'],
            'field' => ['sometimes', 'nullable', Rule::enum(ProductAuditField::class)],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'field.enum' => 'The field must be one of: '.implode(', ', ProductAuditField::values()).'.',
        ];
    }

    public function field(): ?ProductAuditField
    {
        $value = $this->validated('field');

        return $value !== null ? ProductAuditField::from($value) : null;
    }

    public function productId(): ?int
    {
        $value = $this->validated('product_id');

        return $value !== null ? (int) $value : null;
    }

    public function actorId(): ?int
    {
        $value = $this->validated('user_id');

        return $value !== null ? (int) $value : null;
    }

    public function from(): ?Carbon
    {
        $value = $this->validated('from');

        return $value !== null ? Carbon::parse($value)->startOfDay() : null;
    }

    public function to(): ?Carbon
    {
        $value = $this->validated('to');

        return $value !== null ? Carbon::parse($value)->endOfDay() : null;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
