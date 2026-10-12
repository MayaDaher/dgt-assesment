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
     * Forward-only fulfilment path used by admins. Cancellation is not included:
     * that path restores stock and must go through POST /api/orders/{order}/cancel.
     *
     * @return list<self>
     */
    public function allowedAdminTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Paid],
            self::Paid => [self::Processing],
            self::Processing => [self::Shipped],
            self::Shipped => [self::Delivered],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedAdminTransitions(), true);
    }

    /**
     * Column stamped the first time this status is reached. Pending has none
     * because created_at is the placement time.
     */
    public function occurredAtColumn(): ?string
    {
        return match ($this) {
            self::Paid => 'paid_at',
            self::Processing => 'processing_at',
            self::Shipped => 'shipped_at',
            self::Delivered => 'delivered_at',
            self::Cancelled => 'cancelled_at',
            self::Pending => null,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
