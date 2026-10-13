<?php

namespace App\Enums;

enum PromotionAuditField: string
{
    case Code = 'code';
    case Type = 'type';
    case Scope = 'scope';
    case Value = 'value';
    case MinCartAmount = 'min_cart_amount';
    case MaxDiscountAmount = 'max_discount_amount';
    case StartsAt = 'starts_at';
    case EndsAt = 'ends_at';
    case UsageLimit = 'usage_limit';
    case PerCustomerLimit = 'per_customer_limit';
    case IsActive = 'is_active';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
};
