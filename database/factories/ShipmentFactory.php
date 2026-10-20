<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShipmentStatus;
use App\Models\OrderSeller;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 */
final class ShipmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_seller_id' => OrderSeller::factory(),
            'tracking_code' => 'SM'.fake()->unique()->numerify('#########').'BR',
            'carrier' => 'simulated',
            'service_code' => 'simulated_standard',
            'status' => ShipmentStatus::IN_TRANSIT,
            'shipped_at' => now(),
            'estimated_delivery_at' => now()->addDays(5),
            'delivered_at' => null,
        ];
    }

    public function delivered(): static
    {
        return $this->state(fn (): array => [
            'status' => ShipmentStatus::DELIVERED,
            'delivered_at' => now(),
        ]);
    }
}
