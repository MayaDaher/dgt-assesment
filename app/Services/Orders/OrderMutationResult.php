<?php

namespace App\Services\Orders;

use App\Models\Order;

final readonly class OrderMutationResult
{
    public function __construct(
        public Order $order,
        public bool $replayed = false,
    ) {}
}
