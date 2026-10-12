<?php

namespace App\Exceptions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;

class InvalidOrderStatusException extends ApiException
{
    protected int $status = 422;

    protected string $errorCode = 'INVALID_ORDER_STATUS';

    public function __construct(OrderStatus $status)
    {
        $allowed = implode(', ', array_map(fn (OrderStatus $case) => $case->value, OrderStatus::cancellable()));

        parent::__construct("This order cannot be cancelled while its status is [{$status->value}].", [
            'current_status' => $status->value,
            'cancellable_statuses' => array_map(fn (OrderStatus $case) => $case->value, OrderStatus::cancellable()),
            'hint' => "Cancellation is allowed only for: {$allowed}.",
        ]);
    }
}
