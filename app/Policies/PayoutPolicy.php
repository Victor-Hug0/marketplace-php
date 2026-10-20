<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Payout;
use App\Models\User;

final class PayoutPolicy
{
    public function request(User $user): bool
    {
        return $user->hasRole(RoleName::SELLER);
    }

    public function viewAnyAsAdmin(User $user): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }

    public function process(User $user, Payout $payout): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }
}
