<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Address;
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
        $subtotal = fake()->numberBetween(1000, 500000);
        $shipping = fake()->numberBetween(0, 5000);

        return [
            'buyer_id' => User::factory(),
            'status' => OrderStatus::PENDING_PAYMENT,
            'currency' => 'BRL',
            'subtotal_cents' => $subtotal,
            'shipping_cents' => $shipping,
            'total_cents' => $subtotal + $shipping,
            'idempotency_key' => (string) Str::uuid(),
            'address_id' => null,
            'shipping_recipient_name' => fake()->name(),
            'shipping_phone' => fake()->numerify('###########'),
            'shipping_cep' => fake()->numerify('########'),
            'shipping_street' => fake()->streetName(),
            'shipping_number' => (string) fake()->numberBetween(1, 9999),
            'shipping_complement' => fake()->optional()->secondaryAddress(),
            'shipping_district' => fake()->citySuffix(),
            'shipping_city' => fake()->city(),
            'shipping_state' => fake()->randomElement(['SP', 'RJ', 'MG', 'RS', 'PR']),
        ];
    }

    public function forBuyer(User $buyer): static
    {
        return $this->state(fn (): array => [
            'buyer_id' => $buyer->id,
        ]);
    }

    public function withAddress(Address $address): static
    {
        return $this->state(fn (): array => Order::snapshotFromAddress($address));
    }

    public function pendingPayment(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::PENDING_PAYMENT,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::PAID,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::CANCELLED,
        ]);
    }

    public function withTotals(int $subtotalCents, int $shippingCents = 0): static
    {
        return $this->state(fn (): array => [
            'subtotal_cents' => $subtotalCents,
            'shipping_cents' => $shippingCents,
            'total_cents' => $subtotalCents + $shippingCents,
        ]);
    }
}
