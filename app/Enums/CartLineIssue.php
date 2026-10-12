<?php

namespace App\Enums;

enum CartLineIssue: string
{
    case ProductUnavailable = 'product_unavailable';
    case InsufficientStock = 'insufficient_stock';

    public function errorCode(): string
    {
        return match ($this) {
            self::ProductUnavailable => 'PRODUCT_UNAVAILABLE',
            self::InsufficientStock => 'INSUFFICIENT_STOCK',
        };
    }
}
