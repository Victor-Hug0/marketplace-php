<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Dispute;
use App\Models\User;

final class DisputePolicy
{
    public function respond(User $user, Dispute $dispute): bool
    {
        $dispute->loadMissing('orderSeller.store:id,user_id');

        return $dispute->orderSeller?->store?->user_id === $user->id;
    }

    public function viewAnyAsAdmin(User $user): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }

    public function resolve(User $user, Dispute $dispute): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }
}
