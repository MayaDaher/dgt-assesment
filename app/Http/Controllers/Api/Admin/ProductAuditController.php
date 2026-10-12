<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ListProductAuditsRequest;
use App\Http\Resources\ProductAuditResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Models\ProductAudit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;

class ProductAuditController extends Controller
{
    public function index(ListProductAuditsRequest $request, int $product): JsonResponse
    {
        Product::withTrashed()->findOrFail($product);

        return $this->paginate($request, $product);
    }

    public function indexAll(ListProductAuditsRequest $request): JsonResponse
    {
        return $this->paginate($request, $request->productId());
    }

    private function paginate(ListProductAuditsRequest $request, ?int $productId): JsonResponse
    {
        $audits = ProductAudit::query()
            ->with('user')
            ->when($productId !== null, fn (Builder $q) => $q->where('product_id', $productId))
            ->when($request->field() !== null, fn (Builder $q) => $q->where('field', $request->field()))
            ->when($request->actorId() !== null, fn (Builder $q) => $q->where('user_id', $request->actorId()))
            ->when($request->from() !== null, fn (Builder $q) => $q->where('created_at', '>=', $request->from()))
            ->when($request->to() !== null, fn (Builder $q) => $q->where('created_at', '<=', $request->to()))
            ->latest('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return ApiResponse::success(ProductAuditResource::collection($audits));
    }
}
