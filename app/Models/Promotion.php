<?php

namespace App\Models;

use App\Enums\PromotionScope;
use App\Enums\PromotionType;
use Carbon\CarbonInterface;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Promotion extends Model
{
    /** @use HasFactory<PromotionFactory> */
    use HasFactory, SoftDeletes;

    /**
     * `used_count` is not fillable: it is only changed atomically at checkout.
     *
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'description',
        'type',
        'scope',
        'value',
        'min_cart_amount',
        'max_discount_amount',
        'starts_at',
        'ends_at',
        'usage_limit',
        'per_customer_limit',
        'is_active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'used_count' => 0,
        'is_active' => true,
        'scope' => PromotionScope::Order->value,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
            'scope' => PromotionScope::class,
            'value' => 'decimal:2',
            'min_cart_amount' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'usage_limit' => 'integer',
            'per_customer_limit' => 'integer',
            'used_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public static function normalizeCode(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    /**
     * @return Attribute<string, string>
     */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn (string $value) => self::normalizeCode($value));
    }

    /**
     * @return BelongsToMany<Product, $this>
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withTimestamps();
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)->withTimestamps();
    }

    /**
     * @return HasMany<PromotionUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(PromotionUsage::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function hasStarted(?CarbonInterface $at = null): bool
    {
        return $this->starts_at === null || $this->starts_at->lte($at ?? now());
    }

    public function hasEnded(?CarbonInterface $at = null): bool
    {
        return $this->ends_at !== null && $this->ends_at->lt($at ?? now());
    }

    public function hasReachedUsageLimit(): bool
    {
        return $this->usage_limit !== null && $this->used_count >= $this->usage_limit;
    }

    public function usageCountFor(User $user): int
    {
        return $this->usages()->where('user_id', $user->id)->count();
    }

    public function appliesTo(Product $product): bool
    {
        return match ($this->scope) {
            PromotionScope::Order => true,
            PromotionScope::Products => $this->products->contains('id', $product->id),
            PromotionScope::Categories => $product->category_id !== null
                && in_array($product->category_id, $this->scopedCategoryIds(), true),
        };
    }

    /**
     * Attached categories plus their direct children (the catalog tree is one level deep).
     *
     * @return list<int>
     */
    public function scopedCategoryIds(): array
    {
        $this->loadMissing('categories.children');

        $ids = [];

        foreach ($this->categories as $category) {
            $ids[] = $category->id;

            foreach ($category->children as $child) {
                $ids[] = $child->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>|null  $productIds
     * @param  list<int>|null  $categoryIds
     */
    public function syncRestrictions(?array $productIds, ?array $categoryIds): void
    {
        if ($this->scope === PromotionScope::Order) {
            $this->clearRestrictions();

            return;
        }

        if ($this->scope === PromotionScope::Products) {
            $this->categories()->sync([]);

            if ($productIds !== null) {
                $this->products()->sync($productIds);
            }

            return;
        }

        $this->products()->sync([]);

        if ($categoryIds !== null) {
            $this->categories()->sync($categoryIds);
        }
    }

    public function clearRestrictions(): void
    {
        $this->products()->sync([]);
        $this->categories()->sync([]);
    }
}
