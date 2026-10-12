<?php

namespace App\Enums;

enum ProductAuditField: string
{
    case Price = 'price';
    case StockQuantity = 'stock_quantity';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
