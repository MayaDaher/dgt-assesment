<?php

namespace App\Enums;

enum ProductAvailability: string
{
    case InStock = 'in_stock';
    case OutOfStock = 'out_of_stock';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
