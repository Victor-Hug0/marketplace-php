<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StoreStatus;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Store>
 */
final class StoreFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'user_id' => User::factory(),
            'store_name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'description' => fake()->sentence(),
            'commercial_email' => fake()->companyEmail(),
            'commercial_phone' => fake()->numerify('###########'),
            'origin_cep' => fake()->numerify('########'),
            'document_type' => 'cnpj',
            'document' => fake()->unique()->numerify('##############'),
            'status' => StoreStatus::PENDING,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => [
            'status' => StoreStatus::ACTIVE,
            'reviewed_at' => now(),
        ]);
    }
}
