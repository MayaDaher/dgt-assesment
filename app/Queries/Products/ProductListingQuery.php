<?php

namespace App\Queries\Products;

use App\Enums\ProductAvailability;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the public storefront listing: only active, non-deleted products are visible.
 */
class ProductListingQuery
{
    /**
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(ProductFilters $filters): LengthAwarePaginator
    {
        $sort = $filters->sort;

        return Product::query()
            ->active()
            ->with('category')
            ->search($filters->search)
            ->priceBetween($filters->minPrice, $filters->maxPrice)
            ->when($filters->availability === ProductAvailability::InStock, fn (Builder $q) => $q->where('stock_quantity', '>', 0))
            ->when($filters->availability === ProductAvailability::OutOfStock, fn (Builder $q) => $q->outOfStock())
            ->when($filters->category, fn (Builder $q, Category $category) => $q->inCategory($category))
            ->orderBy($sort->column(), $sort->direction())
            ->orderBy('id', $sort->direction())
            ->paginate($filters->perPage)
            ->withQueryString();
    }
}
