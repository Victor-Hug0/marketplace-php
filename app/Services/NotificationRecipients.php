<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RoleName;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class NotificationRecipients
{
    public function buyer(Order $order): ?User
    {
        return $order->loadMissing('buyer')->buyer;
    }

    public function storeOwner(Store $store): ?User
    {
        return $store->loadMissing('owner')->owner;
    }

    /**
     * @return Collection<int, User>
     */
    public function admins(): Collection
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('name', RoleName::ADMIN))
            ->get();
    }
}
