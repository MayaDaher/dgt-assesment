<?php

namespace App\Exceptions\Inventory;

use App\Exceptions\ApiException;
use App\Models\Product;

class InsufficientStockException extends ApiException
{
    protected int $status = 409;

    protected string $errorCode = 'INSUFFICIENT_STOCK';

    public function __construct(Product $product, int $requested, int $available)
    {
        $message = $available > 0
            ? "Only {$available} unit(s) of \"{$product->name}\" are available."
            : "\"{$product->name}\" is out of stock.";

        parent::__construct($message, [
            'product_id' => $product->id,
            'requested_quantity' => $requested,
            'available_quantity' => $available,
        ]);
    }
}
