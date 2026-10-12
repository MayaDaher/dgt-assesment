<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\ProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductRequest;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Http\Resources\ProductResource;
use App\Http\Responses\ApiResponse;
use App\Models\Product;
use App\Services\Catalog\ProductAvailabilityOverlay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(private readonly ProductAvailabilityOverlay $availability) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(ProductStatus::class)],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = Product::query()
            ->with('category')
            ->search($request->input('search'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')
            ->paginate((int) $request->input('per_page', 15))
            ->withQueryString();

        $this->availability->apply($products->getCollection());

        return ApiResponse::success(ProductResource::collection($products));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = Product::query()->create($request->validated());

        return ApiResponse::created(
            new ProductResource($this->availability->one($product->load('category'))),
            'Product created.',
        );
    }

    public function show(Product $product): JsonResponse
    {
        return ApiResponse::success(new ProductResource($this->availability->one($product->loadMissing('category'))));
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $updated = DB::transaction(function () use ($request, $product) {
            $locked = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $locked->update($request->validated());

            return $locked->fresh()->load('category');
        });

        return ApiResponse::success(new ProductResource($this->availability->one($updated)), 'Product updated.');
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return ApiResponse::success(null, 'Product deleted.');
    }

    public function restore(int $product): JsonResponse
    {
        $product = Product::onlyTrashed()->findOrFail($product);
        $product->restore();

        return ApiResponse::success(
            new ProductResource($this->availability->one($product->load('category'))),
            'Product restored.',
        );
    }
}
