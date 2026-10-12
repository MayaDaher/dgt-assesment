<?php

namespace App\Enums;

enum OrderStatusSource: string
{
    case Checkout = 'checkout';
    case Payment = 'payment';
    case Admin = 'admin';
    case Cancel = 'cancel';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
