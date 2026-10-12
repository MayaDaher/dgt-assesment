<?php

namespace App\Enums;

enum PromotionScope: string
{
    case Order = 'order';
    case Products = 'products';
    case Categories = 'categories';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isRestricted(): bool
    {
        return $this !== self::Order;
    }
}
