<?php

namespace App\Exceptions\Cart;

use App\Exceptions\ApiException;

class EmptyCartException extends ApiException
{
    protected int $status = 422;

    protected string $errorCode = 'CART_EMPTY';

    public function __construct()
    {
        parent::__construct('Your cart is empty.');
    }
}
