<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListPromotionAuditsRequest;
use App\Http\Resources\PromotionAuditResource;
use App\Http\Responses\ApiResponse;
use App\Models\Promotion;
use App\Models\PromotionAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class PromotionAuditController extends Controller
{
    public function index(ListPromotionAuditsRequest $request, int $promotion): JsonResponse
    {
        Promotion::withTrashed()->findOrFail($promotion);

        return $this->paginate($request, $promotion);
    }

    public function indexAll(ListPromotionAuditsRequest $request): JsonResponse
    {
        return $this->paginate($request, $request->promotionId());
    }

    private function paginate(ListPromotionAuditsRequest $request, ?int $promotionId): JsonResponse
    {
        $audits = PromotionAudit::query()
            ->with('user')
            ->when($promotionId !== null, fn (Builder $q) => $q->where('promotion_id', $promotionId))
            ->when($request->field() !== null, fn (Builder $q) => $q->where('field', $request->field()))
            ->when($request->actorId() !== null, fn (Builder $q) => $q->where('user_id', $request->actorId()))
            ->when($request->from() !== null, fn (Builder $q) => $q->where('created_at', '>=', $request->from()))
            ->when($request->to() !== null, fn (Builder $q) => $q->where('created_at', '<=', $request->to()))
            ->latest('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return ApiResponse::success(PromotionAuditResource::collection($audits));
    }
}
