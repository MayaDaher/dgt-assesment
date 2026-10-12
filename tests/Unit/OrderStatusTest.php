<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    public function test_admin_fulfilment_path_is_linear_and_forward_only(): void
    {
        $this->assertSame(
            [OrderStatus::Paid],
            OrderStatus::Pending->allowedAdminTransitions(),
        );
        $this->assertSame(
            [OrderStatus::Processing],
            OrderStatus::Paid->allowedAdminTransitions(),
        );
        $this->assertSame(
            [OrderStatus::Shipped],
            OrderStatus::Processing->allowedAdminTransitions(),
        );
        $this->assertSame(
            [OrderStatus::Delivered],
            OrderStatus::Shipped->allowedAdminTransitions(),
        );
        $this->assertSame([], OrderStatus::Delivered->allowedAdminTransitions());
        $this->assertSame([], OrderStatus::Cancelled->allowedAdminTransitions());
    }

    public function test_admin_cannot_skip_or_reverse_or_cancel_through_the_status_endpoint(): void
    {
        $this->assertFalse(OrderStatus::Pending->canTransitionTo(OrderStatus::Shipped));
        $this->assertFalse(OrderStatus::Pending->canTransitionTo(OrderStatus::Cancelled));
        $this->assertFalse(OrderStatus::Paid->canTransitionTo(OrderStatus::Pending));
        $this->assertFalse(OrderStatus::Delivered->canTransitionTo(OrderStatus::Shipped));
        $this->assertTrue(OrderStatus::Pending->canTransitionTo(OrderStatus::Paid));
    }

    public function test_each_status_stamps_a_single_occurred_at_column(): void
    {
        $this->assertNull(OrderStatus::Pending->occurredAtColumn());
        $this->assertSame('paid_at', OrderStatus::Paid->occurredAtColumn());
        $this->assertSame('processing_at', OrderStatus::Processing->occurredAtColumn());
        $this->assertSame('shipped_at', OrderStatus::Shipped->occurredAtColumn());
        $this->assertSame('delivered_at', OrderStatus::Delivered->occurredAtColumn());
        $this->assertSame('cancelled_at', OrderStatus::Cancelled->occurredAtColumn());
    }
}
