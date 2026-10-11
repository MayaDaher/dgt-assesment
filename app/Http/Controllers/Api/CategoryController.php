<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\Category;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    /**
     * Active category tree: root categories with their active subcategories.
     */
    public function index(): JsonResponse
    {
        $categories = Category::query()
            ->active()
            ->roots()
            ->with(['children' => fn (HasMany $q) => $q->active()->orderBy('name')])
            ->orderBy('name')
            ->get();

        return ApiResponse::success(CategoryResource::collection($categories));
    }

    public function show(string $slug): JsonResponse
    {
        $category = Category::query()
            ->active()
            ->where('slug', $slug)
            ->with(['children' => fn (HasMany $q) => $q->active()->orderBy('name')])
            ->firstOrFail();

        return ApiResponse::success(new CategoryResource($category));
    }
}
