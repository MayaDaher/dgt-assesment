<?php

namespace App\Enums;

enum PromotionError: string
{
    case Invalid = 'PROMOTION_INVALID';
    case Inactive = 'PROMOTION_INACTIVE';
    case NotStarted = 'PROMOTION_NOT_STARTED';
    case Expired = 'PROMOTION_EXPIRED';
    case UsageLimitReached = 'PROMOTION_USAGE_LIMIT_REACHED';
    case CustomerLimitReached = 'PROMOTION_CUSTOMER_LIMIT_REACHED';
    case MinimumNotMet = 'PROMOTION_MINIMUM_NOT_MET';

    public function message(): string
    {
        return match ($this) {
            self::Invalid => 'The promotion code is invalid.',
            self::Inactive => 'This promotion code is not active.',
            self::NotStarted => 'This promotion code is not valid yet.',
            self::Expired => 'This promotion code has expired.',
            self::UsageLimitReached => 'This promotion code has reached its usage limit.',
            self::CustomerLimitReached => 'You have already used this promotion code the maximum number of times.',
            self::MinimumNotMet => 'Your cart does not meet the minimum amount for this promotion code.',
        };
    }
}
