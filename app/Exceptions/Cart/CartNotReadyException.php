<?php

namespace App\Exceptions\Cart;

use App\Exceptions\ApiException;

class CartNotReadyException extends ApiException
{
    protected int $status = 422;

    protected string $errorCode = 'CART_NOT_READY';

    /**
     * @param  list<array<string, mixed>>  $issues
     */
    public function __construct(array $issues)
    {
        parent::__construct('Your cart has items that cannot be purchased right now.', [
            'issues' => $issues,
        ]);
    }
}
