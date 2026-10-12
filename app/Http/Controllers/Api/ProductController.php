<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\ListProductsRequest;
use App\Http\Resources\ProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Queries\Products\ProductListingQuery;
use App\Services\Catalog\ProductAvailabilityOverlay;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function __construct(private readonly ProductAvailabilityOverlay $availability) {}

    public function index(ListProductsRequest $request, ProductListingQuery $query): JsonResponse
    {
        return ApiResponse::success(
            ProductResource::collection($query->paginate($request->filters()))
        );
    }

    public function show(int $product): JsonResponse
    {
        $product = Product::query()->active()->with('category')->findOrFail($product);

        return ApiResponse::success(new ProductResource($this->availability->one($product)));
    }
}
