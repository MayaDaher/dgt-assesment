<?php

namespace App\Exceptions\Orders;

use App\Enums\OrderStatus;
use App\Exceptions\ApiException;

class OrderNotPayableException extends ApiException
{
    protected int $status = 422;

    protected string $errorCode = 'ORDER_NOT_PAYABLE';

    public function __construct(OrderStatus $status)
    {
        parent::__construct("This order cannot be paid while its status is [{$status->value}].", [
            'current_status' => $status->value,
            'payable_statuses' => [OrderStatus::Pending->value],
            'hint' => 'Payment capture is allowed only while the order is pending.',
        ]);
    }
}
