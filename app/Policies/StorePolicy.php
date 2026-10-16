<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Store;
use App\Models\User;

final class StorePolicy
{
    public function create(User $user): bool
    {
        return $user->hasRole(RoleName::BUYER);
    }

    public function view(User $user, Store $store): bool
    {
        return $store->user_id === $user->id || $user->hasRole(RoleName::ADMIN);
    }

    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }

    public function review(User $user, Store $store): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }
}
