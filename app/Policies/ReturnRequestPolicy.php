<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\ReturnRequest;
use App\Models\User;

final class ReturnRequestPolicy
{
    public function viewAnyAsAdmin(User $user): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }

    public function decide(User $user, ReturnRequest $returnRequest): bool
    {
        $returnRequest->loadMissing('orderSeller.store:id,user_id');

        return $returnRequest->orderSeller?->store?->user_id === $user->id
            || $user->hasRole(RoleName::ADMIN);
    }
}
