<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProductImage>
 */
final class ProductImageFactory extends Factory
{
    public function definition(): array
    {
        $uuid = Str::uuid()->toString();

        return [
            'product_id' => Product::factory(),
            'path' => "products/{$uuid}/1.jpg",
            'position' => fake()->numberBetween(1, 10),
            'alt_text' => fake()->optional()->sentence(3),
        ];
    }

    public function cover(): static
    {
        return $this->state(fn (): array => [
            'position' => 0,
        ]);
    }
}
