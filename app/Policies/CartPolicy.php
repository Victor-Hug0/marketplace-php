<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Cart;
use App\Models\User;

final class CartPolicy
{
    public function view(User $user, Cart $cart): bool
    {
        return $cart->user_id === $user->id;
    }

    public function update(User $user, Cart $cart): bool
    {
        return $cart->user_id === $user->id;
    }
}
