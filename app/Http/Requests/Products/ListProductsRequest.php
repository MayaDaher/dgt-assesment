<?php

namespace App\Http\Requests\Products;

use App\Enums\ProductAvailability;
use App\Enums\ProductSort;
use App\Models\Category;
use App\Queries\Products\ProductFilters;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListProductsRequest extends FormRequest
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
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'min_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'max_price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999'],
            'availability' => ['sometimes', 'nullable', Rule::enum(ProductAvailability::class)],
            'category' => [
                'sometimes', 'nullable', 'string',
                Rule::exists('categories', 'slug')->where('is_active', true),
            ],
            'sort' => ['sometimes', 'nullable', Rule::enum(ProductSort::class)],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.ProductFilters::MAX_PER_PAGE],
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'availability.enum' => 'The availability must be one of: '.implode(', ', ProductAvailability::values()).'.',
            'sort.enum' => 'The sort must be one of: '.implode(', ', ProductSort::values()).'.',
            'category.exists' => 'The selected category does not exist.',
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $min = $this->input('min_price');
                $max = $this->input('max_price');

                if (is_numeric($min) && is_numeric($max) && (float) $max < (float) $min) {
                    $validator->errors()->add('max_price', 'The max price must be greater than or equal to the min price.');
                }
            },
        ];
    }

    public function filters(): ProductFilters
    {
        $data = $this->validated();

        return new ProductFilters(
            search: filled($data['search'] ?? null) ? trim($data['search']) : null,
            minPrice: isset($data['min_price']) ? (string) $data['min_price'] : null,
            maxPrice: isset($data['max_price']) ? (string) $data['max_price'] : null,
            availability: isset($data['availability']) ? ProductAvailability::from($data['availability']) : null,
            category: isset($data['category']) ? Category::query()->where('slug', $data['category'])->first() : null,
            sort: isset($data['sort']) ? ProductSort::from($data['sort']) : ProductSort::DEFAULT,
            perPage: (int) ($data['per_page'] ?? ProductFilters::DEFAULT_PER_PAGE),
            page: (int) ($data['page'] ?? 1),
        );
    }
}
