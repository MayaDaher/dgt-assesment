<?php

namespace App\Models;

use App\Enums\ProductAuditSource;
use App\Enums\PromotionAuditField;
use Database\Factories\PromotionAuditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PromotionAudit extends Model
{
    /** @use HasFactory<PromotionAuditFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'promotion_id',
        'promotion_code',
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
            'field' => PromotionAuditField::class,
            'source' => ProductAuditSource::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Promotion audits are append-only.');
        });

        static::deleting(function (): never {
            throw new LogicException('Promotion audits are append-only.');
        });
    }

    /**
     * @return BelongsTo<Promotion, $this>
     */
    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class)->withTrashed();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
