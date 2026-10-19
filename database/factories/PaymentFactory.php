<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
final class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'provider' => 'mock',
            'method' => PaymentMethod::PIX,
            'status' => PaymentStatus::PENDING,
            'external_id' => 'mock_pay_'.Str::lower((string) Str::ulid()),
            'idempotency_key' => (string) Str::uuid(),
            'currency' => 'BRL',
            'amount_cents' => fake()->numberBetween(1000, 500000),
            'payload' => [],
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn (): array => [
            'order_id' => $order->id,
            'amount_cents' => $order->total_cents,
            'currency' => $order->currency,
        ]);
    }

    public function method(PaymentMethod $method): static
    {
        return $this->state(fn (): array => [
            'method' => $method,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => PaymentStatus::PAID,
            'paid_at' => now(),
        ]);
    }
}
