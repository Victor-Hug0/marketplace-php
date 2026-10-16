<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\RoleName;
use App\Enums\StoreStatus;
use App\Models\Offer;
use App\Models\User;

final class OfferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole(RoleName::SELLER) || $user->hasRole(RoleName::ADMIN);
    }

    public function view(User $user, Offer $offer): bool
    {
        if ($user->hasRole(RoleName::ADMIN)) {
            return true;
        }

        return $this->ownsOffer($user, $offer);
    }

    public function create(User $user): bool
    {
        return $this->hasActiveStore($user);
    }

    public function update(User $user, Offer $offer): bool
    {
        if ($user->hasRole(RoleName::ADMIN)) {
            return true;
        }

        return $this->ownsOffer($user, $offer) && $this->hasActiveStore($user);
    }

    public function delete(User $user, Offer $offer): bool
    {
        return $this->ownsOffer($user, $offer) && $this->hasActiveStore($user);
    }

    public function pause(User $user, Offer $offer): bool
    {
        if ($user->hasRole(RoleName::ADMIN)) {
            return true;
        }

        return $this->ownsOffer($user, $offer) && $this->hasActiveStore($user);
    }

    public function resume(User $user, Offer $offer): bool
    {
        return $this->ownsOffer($user, $offer) && $this->hasActiveStore($user);
    }

    private function ownsOffer(User $user, Offer $offer): bool
    {
        return $offer->store?->user_id === $user->id
            || $offer->store()->where('user_id', $user->id)->exists();
    }

    private function hasActiveStore(User $user): bool
    {
        if (! $user->hasRole(RoleName::SELLER)) {
            return false;
        }

        return $user->store !== null && $user->store->status === StoreStatus::ACTIVE;
    }
}
