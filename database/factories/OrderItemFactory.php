<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Offer;
use App\Models\OrderItem;
use App\Models\OrderSeller;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrderItem>
 */
final class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        $unitPrice = fake()->randomFloat(2, 10, 200);
        $quantity = fake()->numberBetween(1, 5);

        return [
            'order_seller_id' => OrderSeller::factory(),
            'offer_id' => null,
            'product_id' => Product::factory()->active(),
            'store_id' => Store::factory()->active(),
            'name' => fake()->words(3, true),
            'slug' => Str::slug(fake()->unique()->words(3, true)),
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_total' => bcmul((string) $unitPrice, (string) $quantity, 2),
        ];
    }

    public function forOrderSeller(OrderSeller $orderSeller): static
    {
        return $this->state(fn (): array => [
            'order_seller_id' => $orderSeller->id,
            'store_id' => $orderSeller->store_id,
        ]);
    }

    public function fromOffer(Offer $offer, int $quantity = 1): static
    {
        $unitPrice = $offer->currentPrice();

        return $this->state(fn (): array => [
            'offer_id' => $offer->id,
            'product_id' => $offer->product_id,
            'store_id' => $offer->store_id,
            'name' => $offer->product?->name ?? 'Produto',
            'slug' => $offer->product?->slug ?? 'produto',
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_total' => bcmul($unitPrice, (string) $quantity, 2),
        ]);
    }
}
