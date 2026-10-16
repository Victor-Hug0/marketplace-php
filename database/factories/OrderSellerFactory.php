<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderSellerStatus;
use App\Models\Order;
use App\Models\OrderSeller;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderSeller>
 */
final class OrderSellerFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = fake()->randomFloat(2, 20, 500);

        return [
            'order_id' => Order::factory(),
            'store_id' => Store::factory()->active(),
            'status' => OrderSellerStatus::PENDING_PAYMENT,
            'subtotal' => $subtotal,
            'shipping_total' => '0.00',
            'total' => $subtotal,
        ];
    }

    public function forOrder(Order $order): static
    {
        return $this->state(fn (): array => [
            'order_id' => $order->id,
        ]);
    }

    public function forStore(Store $store): static
    {
        return $this->state(fn (): array => [
            'store_id' => $store->id,
        ]);
    }
}
