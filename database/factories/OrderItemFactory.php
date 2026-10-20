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
        $name = fake()->words(3, true);

        return [
            'order_seller_id' => OrderSeller::factory(),
            'offer_id' => null,
            'product_id' => Product::factory()->active(),
            'store_id' => Store::factory()->active(),
            'product_name' => $name,
            'product_slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'unit_price_cents' => fake()->numberBetween(500, 50000),
            'quantity' => fake()->numberBetween(1, 5),
        ];
    }

    public function forOrderSeller(OrderSeller $orderSeller): static
    {
        return $this->state(fn (): array => [
            'order_seller_id' => $orderSeller->id,
            'store_id' => $orderSeller->store_id,
        ]);
    }

    public function fromOffer(Offer $offer, ?int $unitPriceCents = null): static
    {
        $offer->loadMissing('product');

        $price = $unitPriceCents;
        if ($price === null) {
            $price = (int) round(((float) $offer->currentPrice()) * 100);
        }

        return $this->state(fn (): array => [
            'offer_id' => $offer->id,
            'product_id' => $offer->product_id,
            'store_id' => $offer->store_id,
            'product_name' => $offer->product->name,
            'product_slug' => $offer->product->slug,
            'unit_price_cents' => $price,
        ]);
    }

    public function withQuantity(int $quantity): static
    {
        return $this->state(fn (): array => [
            'quantity' => $quantity,
        ]);
    }

    public function priced(int $unitPriceCents): static
    {
        return $this->state(fn (): array => [
            'unit_price_cents' => $unitPriceCents,
        ]);
    }
}
