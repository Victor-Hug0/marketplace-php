<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Order;
use App\Models\User;

final class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Order $order): bool
    {
        if ($order->buyer_id === $user->id) {
            return true;
        }

        return $user->hasRole(RoleName::ADMIN);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $order->buyer_id === $user->id;
    }

    public function viewAnyAsAdmin(User $user): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }
}
