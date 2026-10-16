<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
final class OfferFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory()->active(),
            'store_id' => Store::factory()->active(),
            'price' => fake()->randomFloat(2, 10, 5000),
            'promotional_price' => null,
            'promo_starts_at' => null,
            'promo_ends_at' => null,
            'stock' => fake()->numberBetween(1, 100),
            'reserved_stock' => 0,
            'status' => OfferStatus::ACTIVE,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => OfferStatus::ACTIVE,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => [
            'status' => OfferStatus::PAUSED,
        ]);
    }

    public function belongingToStore(Store $store): static
    {
        return $this->state(fn (): array => [
            'store_id' => $store->id,
        ]);
    }

    public function onProduct(Product $product): static
    {
        return $this->state(fn (): array => [
            'product_id' => $product->id,
        ]);
    }

    public function withStock(int $stock, int $reserved = 0): static
    {
        return $this->state(fn (): array => [
            'stock' => $stock,
            'reserved_stock' => $reserved,
        ]);
    }

    public function priced(string $price, ?string $promotionalPrice = null): static
    {
        return $this->state(fn (): array => [
            'price' => $price,
            'promotional_price' => $promotionalPrice,
            'promo_starts_at' => $promotionalPrice !== null ? now()->subDay() : null,
            'promo_ends_at' => $promotionalPrice !== null ? now()->addDays(7) : null,
        ]);
    }
}
