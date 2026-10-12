<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Queries\Orders\OrderFilters;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_number',
        'user_id',
        'promotion_id',
        'promotion_code',
        'status',
        'subtotal',
        'discount_amount',
        'total',
        'idempotency_key',
        'cancelled_at',
        'paid_at',
        'processing_at',
        'shipped_at',
        'delivered_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => OrderStatus::Pending->value,
        'discount_amount' => '0.00',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'cancelled_at' => 'datetime',
            'paid_at' => 'datetime',
            'processing_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (blank($order->order_number)) {
                $order->order_number = self::generateNumber();
            }
        });
    }

    public static function generateNumber(): string
    {
        return 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class)->withTrashed();
    }

    /**
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasOne<PromotionUsage, $this>
     */
    public function promotionUsage(): HasOne
    {
        return $this->hasOne(PromotionUsage::class);
    }

    /**
     * @return HasMany<OrderStatusChange, $this>
     */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(OrderStatusChange::class)->orderBy('id');
    }

    /**
     * @param  Builder<Order>  $query
     */
    #[Scope]
    protected function filtered(Builder $query, OrderFilters $filters): void
    {
        $query
            ->when($filters->status !== null, fn (Builder $q) => $q->where('status', $filters->status))
            ->when($filters->userId !== null, fn (Builder $q) => $q->where('user_id', $filters->userId))
            ->when($filters->from !== null, fn (Builder $q) => $q->where('created_at', '>=', $filters->from))
            ->when($filters->to !== null, fn (Builder $q) => $q->where('created_at', '<=', $filters->to));

        $term = trim((string) $filters->search);

        if ($term === '') {
            return;
        }

        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
        $like = "%{$escaped}%";

        $query->where(function (Builder $inner) use ($like) {
            $inner
                ->whereRaw("order_number LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('user', function (Builder $user) use ($like) {
                    $user
                        ->whereRaw("email LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("name LIKE ? ESCAPE '!'", [$like]);
                });
        });
    }

    public function canBeCancelled(): bool
    {
        return $this->status->isCancellable();
    }

    public function canBePaid(): bool
    {
        return $this->status === OrderStatus::Pending;
    }
}
