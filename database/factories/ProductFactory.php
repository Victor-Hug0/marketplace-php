<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ProductStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
final class ProductFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'store_id' => Store::factory(),
            'category_id' => Category::factory(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'description' => fake()->optional()->paragraph(),
            'status' => ProductStatus::DRAFT,
            'rejection_reason' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::ACTIVE,
            'rejection_reason' => null,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::PAUSED,
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::REJECTED,
            'rejection_reason' => 'Produto fora das regras do marketplace.',
        ]);
    }
}
