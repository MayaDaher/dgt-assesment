<?php

namespace App\Exceptions\Cart;

use App\Exceptions\ApiException;

class CartNotFoundException extends ApiException
{
    protected int $status = 404;

    protected string $errorCode = 'CART_NOT_FOUND';

    public function __construct()
    {
        parent::__construct('Cart not found.');
    }
}
