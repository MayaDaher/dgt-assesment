<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePromotionRequest;
use App\Http\Requests\Admin\UpdatePromotionRequest;
use App\Http\Resources\PromotionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Promotion;
use Illuminate\Http\JsonResponse;

class PromotionController extends Controller
{
    public function index(): JsonResponse
    {
        $promotions = Promotion::query()->latest('id')->paginate(15)->withQueryString();

        return ApiResponse::success(PromotionResource::collection($promotions));
    }

    public function store(StorePromotionRequest $request): JsonResponse
    {
        $promotion = Promotion::query()->create($request->validated());

        return ApiResponse::created(new PromotionResource($promotion), 'Promotion created.');
    }

    public function show(Promotion $promotion): JsonResponse
    {
        return ApiResponse::success(new PromotionResource($promotion));
    }

    public function update(UpdatePromotionRequest $request, Promotion $promotion): JsonResponse
    {
        $promotion->update($request->validated());

        return ApiResponse::success(new PromotionResource($promotion->fresh()), 'Promotion updated.');
    }

    public function destroy(Promotion $promotion): JsonResponse
    {
        $promotion->delete();

        return ApiResponse::success(null, 'Promotion deleted.');
    }
}
