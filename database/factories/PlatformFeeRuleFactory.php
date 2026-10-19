<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlatformFeeType;
use App\Models\PlatformFeeRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformFeeRule>
 */
final class PlatformFeeRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Comissão global',
            'type' => PlatformFeeType::PERCENTAGE,
            'percent_bps' => 1000,
            'fixed_cents' => null,
            'starts_at' => now()->subDay(),
            'ends_at' => null,
        ];
    }

    public function percentage(int $bps): static
    {
        return $this->state(fn (): array => [
            'type' => PlatformFeeType::PERCENTAGE,
            'percent_bps' => $bps,
            'fixed_cents' => null,
        ]);
    }

    public function fixed(int $cents): static
    {
        return $this->state(fn (): array => [
            'type' => PlatformFeeType::FIXED,
            'percent_bps' => null,
            'fixed_cents' => $cents,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function future(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->addDay(),
            'ends_at' => null,
        ]);
    }
}
