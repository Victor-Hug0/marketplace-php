<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ShippingZone;
use App\Models\ShippingRateRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippingRateRule>
 */
final class ShippingRateRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'service_code' => 'simulated_standard',
            'service_name' => 'Entrega padrão (simulada)',
            'zone' => ShippingZone::NATIONAL,
            'max_weight_grams' => 30000,
            'price_cents' => 2500,
            'eta_days' => 7,
            'is_active' => true,
        ];
    }

    public function zone(ShippingZone $zone): static
    {
        return $this->state(fn (): array => ['zone' => $zone]);
    }

    public function service(string $code, string $name): static
    {
        return $this->state(fn (): array => [
            'service_code' => $code,
            'service_name' => $name,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
