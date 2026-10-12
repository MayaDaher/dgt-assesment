<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Guest cart token
    |--------------------------------------------------------------------------
    |
    | Guests identify their cart with this request header. The same value is
    | returned as `cart_token` in the payload and echoed back on the response
    | so clients can persist it across page loads.
    |
    */

    'token_header' => 'X-Cart-Token',

    /*
    |--------------------------------------------------------------------------
    | Stock reservation TTL
    |--------------------------------------------------------------------------
    |
    | Adding an item holds that quantity for this cart until checkout, the
    | line is removed, or the hold expires. Any cart activity (read or write)
    | slides the expiry forward so a customer who is still shopping does not
    | lose the reservation mid-session.
    |
    */

    'reservation_ttl_minutes' => (int) env('CART_RESERVATION_TTL_MINUTES', 15),

];
