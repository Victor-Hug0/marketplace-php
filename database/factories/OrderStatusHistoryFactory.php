<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
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
            'statusable_id' => Order::factory(),
            'statusable_type' => (new Order)->getMorphClass(),
            'from_status' => null,
            'to_status' => OrderStatus::PENDING_PAYMENT->value,
            'actor_id' => User::factory(),
            'actor_type' => (new User)->getMorphClass(),
            'reason' => null,
            'created_at' => now(),
        ];
    }
}
