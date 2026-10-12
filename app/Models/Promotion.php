<?php

namespace App\Models;

use App\Enums\PromotionType;
use Carbon\CarbonInterface;
use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PromotionType::class,
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
}
