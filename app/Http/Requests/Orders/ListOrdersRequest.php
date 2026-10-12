<?php

namespace App\Http\Requests\Orders;

use App\Enums\OrderStatus;
use App\Queries\Orders\OrderFilters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ListOrdersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', Rule::enum(OrderStatus::class)],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.OrderFilters::MAX_PER_PAGE],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.enum' => 'The status must be one of: '.implode(', ', OrderStatus::values()).'.',
        ];
    }

    public function filters(): OrderFilters
    {
        $data = $this->validated();

        return new OrderFilters(
            status: isset($data['status']) ? OrderStatus::from($data['status']) : null,
            from: isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : null,
            to: isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : null,
            perPage: (int) ($data['per_page'] ?? OrderFilters::DEFAULT_PER_PAGE),
        );
    }
}
