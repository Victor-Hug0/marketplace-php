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
        $subtotal = fake()->numberBetween(1000, 200000);
        $shipping = fake()->numberBetween(0, 3000);

        return [
            'order_id' => Order::factory(),
            'store_id' => Store::factory()->active(),
            'status' => OrderSellerStatus::PENDING_PAYMENT,
            'subtotal_cents' => $subtotal,
            'shipping_cents' => $shipping,
            'total_cents' => $subtotal + $shipping,
            'shipping_service_code' => null,
            'shipping_service_name' => null,
            'shipping_eta_days' => null,
            'shipping_quoted_at' => null,
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

    public function pendingPayment(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderSellerStatus::PENDING_PAYMENT,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderSellerStatus::PAID,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderSellerStatus::CANCELLED,
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
