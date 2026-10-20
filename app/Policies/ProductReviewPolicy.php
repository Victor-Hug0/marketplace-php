<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OrderItem;
use App\Models\User;

final class ProductReviewPolicy
{
    public function create(User $user, OrderItem $orderItem): bool
    {
        $orderItem->loadMissing('orderSeller.order:id,buyer_id');

        return $orderItem->orderSeller?->order?->buyer_id === $user->id;
    }
}
