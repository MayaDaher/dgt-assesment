<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Processing = 'processing';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * Orders can be cancelled until they are handed to fulfilment.
     *
     * @return list<self>
     */
    public static function cancellable(): array
    {
        return [self::Pending, self::Paid];
    }

    public function isCancellable(): bool
    {
        return in_array($this, self::cancellable(), true);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
