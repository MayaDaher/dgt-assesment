<?php

namespace App\Queries\Products;

use App\Enums\ProductAvailability;
use App\Enums\ProductSort;
use App\Models\Category;

final readonly class ProductFilters
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 100;

    public function __construct(
        public ?string $search = null,
        public ?string $minPrice = null,
        public ?string $maxPrice = null,
        public ?ProductAvailability $availability = null,
        public ?Category $category = null,
        public ProductSort $sort = ProductSort::DEFAULT,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public int $page = 1,
    ) {}
}
