<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\OrderSeller;
use App\Models\User;

final class OrderSellerPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleName::SELLER) || $user->hasRole(RoleName::ADMIN);
    }

    public function view(User $user, OrderSeller $orderSeller): bool
    {
        $orderSeller->loadMissing('store');

        if ($orderSeller->store?->user_id === $user->id) {
            return true;
        }

        return $user->hasRole(RoleName::ADMIN);
    }

    public function refund(User $user, OrderSeller $orderSeller): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }

    public function cancel(User $user, OrderSeller $orderSeller): bool
    {
        $orderSeller->loadMissing('store');

        return $orderSeller->store?->user_id === $user->id;
    }
}
