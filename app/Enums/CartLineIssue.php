<?php

namespace App\Enums;

enum CartLineIssue: string
{
    case ProductUnavailable = 'product_unavailable';
    case InsufficientStock = 'insufficient_stock';
}
