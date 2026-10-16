<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Order;
use App\Models\User;

final class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        if ($order->buyer_id === $user->id) {
            return true;
        }

        return $user->hasRole(RoleName::ADMIN);
    }
}
