<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CartItem;
use App\Models\User;

final class CartItemPolicy
{
    public function update(User $user, CartItem $item): bool
    {
        return $this->owns($user, $item);
    }

    public function delete(User $user, CartItem $item): bool
    {
        return $this->owns($user, $item);
    }

    private function owns(User $user, CartItem $item): bool
    {
        $item->loadMissing('cart');

        return $item->cart?->user_id === $user->id;
    }
}
