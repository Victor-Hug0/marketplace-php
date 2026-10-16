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
            'store_id' => Store::factory()->active(),
            'category_id' => Category::factory(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'description' => fake()->optional()->paragraph(),
            'status' => ProductStatus::DRAFT,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::DRAFT,
            'rejection_reason' => null,
            'reviewed_by' => null,
            'reviewed_at' => null,
        ]);
    }

    public function pendingModeration(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::PENDING_MODERATION,
            'rejection_reason' => null,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::ACTIVE,
            'rejection_reason' => null,
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(string $reason = 'Produto fora do padrão da plataforma.'): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::REJECTED,
            'rejection_reason' => $reason,
            'reviewed_at' => now(),
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (): array => [
            'status' => ProductStatus::PAUSED,
            'reviewed_at' => now(),
        ]);
    }

    public function belongingToStore(Store $store): static
    {
        return $this->state(fn (): array => [
            'store_id' => $store->id,
        ]);
    }

    public function inCategory(Category $category): static
    {
        return $this->state(fn (): array => [
            'category_id' => $category->id,
        ]);
    }
}
