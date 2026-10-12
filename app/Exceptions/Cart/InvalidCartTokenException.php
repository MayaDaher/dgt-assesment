<?php

namespace App\Exceptions\Cart;

use App\Exceptions\ApiException;

class InvalidCartTokenException extends ApiException
{
    protected int $status = 422;

    protected string $errorCode = 'CART_TOKEN_INVALID';

    public function __construct()
    {
        parent::__construct('The cart token must be a UUID.');
    }
}
