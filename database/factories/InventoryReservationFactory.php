<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\InventoryReservation;
use App\Models\Offer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryReservation>
 */
final class InventoryReservationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
            'user_id' => User::factory(),
            'quantity' => 1,
            'status' => ReservationStatus::ACTIVE,
            'expires_at' => now()->addMinutes(15),
        ];
    }

    public function released(): static
    {
        return $this->state(fn (): array => [
            'status' => ReservationStatus::RELEASED,
        ]);
    }

    public function committed(): static
    {
        return $this->state(fn (): array => [
            'status' => ReservationStatus::COMMITTED,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'status' => ReservationStatus::ACTIVE,
            'expires_at' => now()->subMinute(),
        ]);
    }
}
