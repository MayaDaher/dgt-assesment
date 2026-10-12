<?php

namespace App\Exceptions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;

class InvalidOrderTransitionException extends ApiException
{
    protected int $status = 422;

    protected string $errorCode = 'INVALID_ORDER_TRANSITION';

    public function __construct(OrderStatus $from, OrderStatus $to)
    {
        $allowed = $from->allowedAdminTransitions();

        parent::__construct("Orders cannot move from [{$from->value}] to [{$to->value}].", [
            'current_status' => $from->value,
            'requested_status' => $to->value,
            'allowed_statuses' => array_map(fn (OrderStatus $status) => $status->value, $allowed),
        ]);
    }
}
