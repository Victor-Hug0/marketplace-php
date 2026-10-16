<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\OrderStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderStatusHistory>
 */
final class OrderStatusHistoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'statusable_type' => (new Order)->getMorphClass(),
            'statusable_id' => Order::factory(),
            'from_status' => null,
            'to_status' => OrderStatus::PENDING_PAYMENT->value,
            'actor_id' => null,
            'reason' => null,
        ];
    }

    public function forStatusable(Order|OrderSeller $statusable): static
    {
        return $this->state(fn (): array => [
            'statusable_type' => $statusable->getMorphClass(),
            'statusable_id' => $statusable->id,
        ]);
    }

    public function transition(?string $from, string $to): static
    {
        return $this->state(fn (): array => [
            'from_status' => $from,
            'to_status' => $to,
        ]);
    }

    public function byActor(User $actor): static
    {
        return $this->state(fn (): array => [
            'actor_id' => $actor->id,
        ]);
    }

    public function withReason(string $reason): static
    {
        return $this->state(fn (): array => [
            'reason' => $reason,
        ]);
    }
}
