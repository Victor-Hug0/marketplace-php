<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Models\Category;
use App\Models\User;

final class CategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }

    public function manage(User $user, Category $category): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }
}
