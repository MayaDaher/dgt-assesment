<?php

namespace App\Services\Catalog;

use App\Queries\Products\ProductFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

/**
 * Caches the public product listing per filter set.
 *
 * Writes bump a version key so every listing entry is dropped at once, without
 * needing cache tags (which the database/array stores do not support).
 */
class CatalogCache
{
    public const VERSION_KEY = 'catalog.version';

    public const TTL_SECONDS = 60;

    /**
     * @param  callable(): LengthAwarePaginator<int, mixed>  $callback
     * @return LengthAwarePaginator<int, mixed>
     */
    public function rememberListing(ProductFilters $filters, callable $callback): LengthAwarePaginator
    {
        return Cache::remember($this->listingKey($filters), self::TTL_SECONDS, $callback);
    }

    public function bump(): void
    {
        Cache::forever(self::VERSION_KEY, $this->version() + 1);
    }

    public function version(): int
    {
        return (int) Cache::get(self::VERSION_KEY, 1);
    }

    private function listingKey(ProductFilters $filters): string
    {
        return 'catalog.products.'.$this->version().'.'.md5(serialize([
            $filters->search,
            $filters->minPrice,
            $filters->maxPrice,
            $filters->availability?->value,
            $filters->category?->id,
            $filters->sort->value,
            $filters->perPage,
            $filters->page,
        ]));
    }
}
