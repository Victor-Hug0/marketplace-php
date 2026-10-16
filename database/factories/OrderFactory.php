<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 20, 500);

        return [
            'buyer_id' => User::factory(),
            'status' => OrderStatus::PENDING_PAYMENT,
            'currency' => 'BRL',
            'subtotal' => $subtotal,
            'shipping_total' => '0.00',
            'total' => $subtotal,
            'address_snapshot' => [
                'recipient_name' => fake()->name(),
                'phone' => fake()->numerify('###########'),
                'cep' => fake()->numerify('########'),
                'street' => fake()->streetName(),
                'number' => (string) fake()->numberBetween(1, 999),
                'complement' => null,
                'district' => fake()->citySuffix(),
                'city' => fake()->city(),
                'state' => 'SP',
            ],
            'idempotency_key' => (string) Str::uuid(),
        ];
    }

    public function forBuyer(User $buyer): static
    {
        return $this->state(fn (): array => [
            'buyer_id' => $buyer->id,
        ]);
    }

    public function pendingPayment(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::PENDING_PAYMENT,
        ]);
    }
}
