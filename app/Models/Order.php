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
        'idempotency_scope',
        'shipping_name',
        'shipping_phone',
        'shipping_address_line1',
        'shipping_address_line2',
        'shipping_city',
        'shipping_state',
        'shipping_postal_code',
        'shipping_country',
        'cancelled_at',
        'paid_at',
        'processing_at',
        'shipped_at',
        'delivered_at',
        'payment_due_at',
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
            'payment_due_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if (blank($order->order_number)) {
                $order->order_number = self::generateNumber();
            }

            if (filled($order->idempotency_key) && blank($order->idempotency_scope) && $order->user_id !== null) {
                $order->idempotency_scope = 'u:'.$order->user_id;
            }

            $pending = $order->status === OrderStatus::Pending
                || $order->status === OrderStatus::Pending->value;

            if ($pending && $order->payment_due_at === null) {
                $order->payment_due_at = now()->addMinutes(self::unpaidPendingMinutes());
            }
        });
    }

    public static function generateNumber(): string
    {
        return 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(8));
    }

    public static function unpaidPendingMinutes(): int
    {
        return max(1, (int) config('orders.unpaid_pending_minutes', 30));
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

    /**
     * @param  Builder<Order>  $query
     */
    #[Scope]
    protected function unpaidExpired(Builder $query): void
    {
        $query
            ->where('status', OrderStatus::Pending)
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<=', now());
    }

    public function canBeCancelled(): bool
    {
        return $this->status->isCancellable();
    }

    public function canBePaid(): bool
    {
        return $this->status === OrderStatus::Pending;
    }

    /**
     * @return array<string, string|null>
     */
    public function shipping(): array
    {
        return [
            'name' => $this->shipping_name,
            'phone' => $this->shipping_phone,
            'address_line1' => $this->shipping_address_line1,
            'address_line2' => $this->shipping_address_line2,
            'city' => $this->shipping_city,
            'state' => $this->shipping_state,
            'postal_code' => $this->shipping_postal_code,
            'country' => $this->shipping_country,
        ];
    }
}
