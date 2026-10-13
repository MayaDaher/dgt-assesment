<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function viewAll(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Order $order): bool
    {
        return $user->isAdmin() || $this->owns($user, $order);
    }

    public function update(User $user, Order $order): bool
    {
        return $user->isAdmin();
    }

    public function pay(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $this->owns($user, $order);
    }

    private function owns(User $user, Order $order): bool
    {
        return $user->id === $order->user_id;
    }
}
