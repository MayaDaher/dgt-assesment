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
        $promotions = Promotion::query()
            ->with(['products:id', 'categories:id'])
            ->latest('id')
            ->paginate(15)
            ->withQueryString();

        return ApiResponse::success(PromotionResource::collection($promotions));
    }

    public function store(StorePromotionRequest $request): JsonResponse
    {
        $promotion = Promotion::query()->create($request->promotionAttributes());
        $promotion->syncRestrictions($request->productIds(), $request->categoryIds());

        return ApiResponse::created(
            new PromotionResource($promotion->load(['products:id', 'categories:id'])),
            'Promotion created.',
        );
    }

    public function show(Promotion $promotion): JsonResponse
    {
        return ApiResponse::success(new PromotionResource($promotion->load(['products:id', 'categories:id'])));
    }

    public function update(UpdatePromotionRequest $request, Promotion $promotion): JsonResponse
    {
        $promotion->update($request->promotionAttributes());
        $promotion->syncRestrictions($request->productIds(), $request->categoryIds());

        return ApiResponse::success(
            new PromotionResource($promotion->fresh()->load(['products:id', 'categories:id'])),
            'Promotion updated.',
        );
    }

    public function destroy(Promotion $promotion): JsonResponse
    {
        $promotion->delete();

        return ApiResponse::success(null, 'Promotion deleted.');
    }

    public function restore(int $promotion): JsonResponse
    {
        $promotion = Promotion::onlyTrashed()->findOrFail($promotion);
        $promotion->restore();

        return ApiResponse::success(
            new PromotionResource($promotion->load(['products:id', 'categories:id'])),
            'Promotion restored.',
        );
    }
}
