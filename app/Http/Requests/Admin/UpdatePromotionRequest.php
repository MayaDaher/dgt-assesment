<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesPromotionPayload;
use App\Models\Promotion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdatePromotionRequest extends FormRequest
{
    use ValidatesPromotionPayload;

    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Promotion $promotion */
        $promotion = $this->route('promotion');

        return $this->promotionRules($promotion);
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        /** @var Promotion $promotion */
        $promotion = $this->route('promotion');

        return $this->promotionAfter($promotion);
    }
}
