<?php

namespace App\Models;

use App\Enums\ProductAuditField;
use App\Enums\ProductAuditSource;
use Database\Factories\ProductAuditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ProductAudit extends Model
{
    /** @use HasFactory<ProductAuditFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'product_sku',
        'product_name',
        'user_id',
        'field',
        'old_value',
        'new_value',
        'source',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field' => ProductAuditField::class,
            'source' => ProductAuditSource::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Product audits are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Product audits are append-only.');
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
