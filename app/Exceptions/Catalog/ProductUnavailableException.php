<?php

namespace App\Exceptions\Catalog;

use App\Exceptions\ApiException;
use App\Models\Product;

class ProductUnavailableException extends ApiException
{
    protected int $status = 422;

    protected string $errorCode = 'PRODUCT_UNAVAILABLE';

    public function __construct(Product $product)
    {
        parent::__construct("\"{$product->name}\" is no longer available for purchase.", [
            'product_id' => $product->id,
        ]);
    }
}
