<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Promotions\PromotionCodeRequest;
use App\Http\Resources\PromotionPreviewResource;
use App\Http\Responses\ApiResponse;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;

class PromotionController extends Controller
{
    public function __construct(private readonly CartService $cart) {}

    /**
     * Checks whether the customer can use the code with their current cart, without applying it.
     */
    public function validateCode(PromotionCodeRequest $request): JsonResponse
    {
        $preview = $this->cart->previewPromotion($this->cart->resolve($request), $request->code());

        return ApiResponse::success(new PromotionPreviewResource($preview), 'The promotion code is valid.');
    }
}
