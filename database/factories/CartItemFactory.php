<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CartItem>
 */
final class CartItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'cart_id' => Cart::factory(),
            'offer_id' => Offer::factory()->withStock(10),
            'quantity' => fake()->numberBetween(1, 3),
        ];
    }

    public function forCart(Cart $cart): static
    {
        return $this->state(fn (): array => [
            'cart_id' => $cart->id,
        ]);
    }

    public function forOffer(Offer $offer): static
    {
        return $this->state(fn (): array => [
            'offer_id' => $offer->id,
        ]);
    }
}
