<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\Product;
use App\Models\User;

final class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleName::ADMIN) || $user->hasRole(RoleName::SELLER);
    }

    public function view(User $user, Product $product): bool
    {
        if ($user->hasRole(RoleName::ADMIN)) {
            return true;
        }

        return $this->ownsProduct($user, $product);
    }

    public function create(User $user): bool
    {
        return $this->hasActiveStore($user);
    }

    public function update(User $user, Product $product): bool
    {
        if ($user->hasRole(RoleName::ADMIN)) {
            return true;
        }

        return $this->ownsProduct($user, $product) && $this->hasActiveStore($user);
    }

    public function delete(User $user, Product $product): bool
    {
        if ($user->hasRole(RoleName::ADMIN)) {
            return true;
        }

        return $this->ownsProduct($user, $product) && $this->hasActiveStore($user);
    }

    public function submit(User $user, Product $product): bool
    {
        return $this->ownsProduct($user, $product) && $this->hasActiveStore($user);
    }

    public function manageImages(User $user, Product $product): bool
    {
        if ($user->hasRole(RoleName::ADMIN)) {
            return true;
        }

        return $this->ownsProduct($user, $product) && $this->hasActiveStore($user);
    }

    public function pause(User $user, Product $product): bool
    {
        if ($user->hasRole(RoleName::ADMIN)) {
            return true;
        }

        return $this->ownsProduct($user, $product) && $this->hasActiveStore($user);
    }

    public function resume(User $user, Product $product): bool
    {
        return $this->ownsProduct($user, $product) && $this->hasActiveStore($user);
    }

    public function review(User $user, Product $product): bool
    {
        return $user->hasRole(RoleName::ADMIN);
    }

    private function ownsProduct(User $user, Product $product): bool
    {
        return $product->store?->user_id === $user->id
            || $product->store()->where('user_id', $user->id)->exists();
    }

    private function hasActiveStore(User $user): bool
    {
        if (! $user->hasRole(RoleName::SELLER)) {
            return false;
        }

        return $user->store !== null && $user->store->status === StoreStatus::ACTIVE;
    }
}
