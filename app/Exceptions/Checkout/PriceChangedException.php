<?php

namespace App\Exceptions\Checkout;

use App\Exceptions\ApiException;
use App\Support\Money;

/**
 * The client confirmed a total that no longer matches the live cart.
 * Checkout must not place at a price the shopper has not seen.
 */
class PriceChangedException extends ApiException
{
    protected int $status = 409;

    protected string $errorCode = 'PRICE_CHANGED';

    public function __construct(int $expectedCents, int $currentCents, int $subtotalCents, int $discountCents)
    {
        parent::__construct(
            'The cart total changed since you last viewed it. Confirm the new total to place the order.',
            [
                'expected_total' => Money::format($expectedCents),
                'current_total' => Money::format($currentCents),
                'current_subtotal' => Money::format($subtotalCents),
                'current_discount' => Money::format($discountCents),
                'hint' => 'GET /cart for the current total, then retry POST /checkout with that expected_total.',
            ],
        );
    }
}
