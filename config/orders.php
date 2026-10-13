<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Unpaid pending window
    |--------------------------------------------------------------------------
    |
    | Checkout decrements on-hand stock immediately. The customer then has this
    | many minutes to capture payment. `orders:expire-pending` cancels overdue
    | pending orders through the same path as a customer cancel (stock restore,
    | promo release, history). Paid orders are never expired.
    |
    */

    'unpaid_pending_minutes' => max(1, (int) env('ORDERS_UNPAID_PENDING_MINUTES', 30)),

];
