<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OrderItem;
use App\Models\ProductReview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductReview>
 */
final class ProductReviewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'order_item_id' => OrderItem::factory(),
            'user_id' => fn (array $attributes): string => OrderItem::query()
                ->findOrFail($attributes['order_item_id'])
                ->orderSeller->order->buyer_id,
            'product_id' => fn (array $attributes): string => OrderItem::query()
                ->findOrFail($attributes['order_item_id'])
                ->product_id,
            'store_id' => fn (array $attributes): string => OrderItem::query()
                ->findOrFail($attributes['order_item_id'])
                ->store_id,
            'rating' => fake()->numberBetween(1, 5),
            'title' => fake()->optional()->sentence(4),
            'body' => fake()->optional()->paragraph(),
        ];
    }
}
