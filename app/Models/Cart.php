<?php

namespace App\Models;

use Database\Factories\CartFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The cart stores products and quantities only. Prices are never stored here;
 * they are always read from the products table so totals cannot be tampered with.
 *
 * Authenticated carts are keyed by `user_id`. Guest carts are keyed by `token`
 * (sent as `X-Cart-Token`). Stock holds live in `stock_reservations` with a TTL.
 */
class Cart extends Model
{
    /** @use HasFactory<CartFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'token',
        'promotion_id',
    ];

    public function isGuest(): bool
    {
        return $this->user_id === null;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CartItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    /**
     * @return HasMany<StockReservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    /**
     * Includes soft-deleted promotions so the cart can report that the code is no longer valid.
     *
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class)->withTrashed();
    }

    public function isEmpty(): bool
    {
        return ! $this->items()->exists();
    }
}
