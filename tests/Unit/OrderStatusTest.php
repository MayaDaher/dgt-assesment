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
}
