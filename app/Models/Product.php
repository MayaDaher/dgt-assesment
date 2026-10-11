<?php

namespace App\Models;

use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'name',
        'sku',
        'description',
        'price',
        'stock_quantity',
        'status',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'stock_quantity' => 0,
        'status' => ProductStatus::Active->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'status' => ProductStatus::class,
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function sku(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => mb_strtoupper(trim($value)),
        );
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', ProductStatus::Active);
    }

    /**
     * Products a customer can actually buy right now.
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->where('status', ProductStatus::Active)->where('stock_quantity', '>', 0);
    }

    /**
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function outOfStock(Builder $query): void
    {
        $query->where('stock_quantity', 0);
    }

    /**
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);

        $query->whereRaw("name LIKE ? ESCAPE '!'", ["%{$escaped}%"]);
    }

    /**
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function priceBetween(Builder $query, int|float|string|null $min, int|float|string|null $max): void
    {
        $query
            ->when($min !== null && $min !== '', fn (Builder $q) => $q->where('price', '>=', $min))
            ->when($max !== null && $max !== '', fn (Builder $q) => $q->where('price', '<=', $max));
    }

    /**
     * Products in the category or any of its subcategories.
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function inCategory(Builder $query, Category $category): void
    {
        $query->whereIn('category_id', $category->selfAndChildIds());
    }

    public function isActive(): bool
    {
        return $this->status === ProductStatus::Active;
    }

    public function isAvailable(): bool
    {
        return $this->isActive() && $this->stock_quantity > 0;
    }

    public function hasStockFor(int $quantity): bool
    {
        return $this->isActive() && $this->stock_quantity >= $quantity;
    }
}
